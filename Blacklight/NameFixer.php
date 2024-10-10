<?php

namespace Blacklight;

use App\Models\Category;
use App\Models\Predb;
use App\Models\Release;
use App\Models\UsenetGroup;
use Blacklight\utility\Utility;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Class NameFixer.
 */
class NameFixer
{
    public const PREDB_REGEX = '/([\w\(\) \-–]+[\s\._,&+\'-–]([\!\w&\',@#.\+\(\)]+[\s\._–-])+[\w\(\)]+(.?-)\w+)/ui';

    // Constants for name fixing status
    public const PROC_NFO_NONE = 0;

    public const PROC_NFO_DONE = 1;

    public const PROC_FILES_NONE = 0;

    public const PROC_FILES_DONE = 1;

    public const PROC_PAR2_NONE = 0;

    public const PROC_PAR2_DONE = 1;

    public const PROC_UID_NONE = 0;

    public const PROC_UID_DONE = 1;

    public const PROC_HASH16K_NONE = 0;

    public const PROC_HASH16K_DONE = 1;

    public const PROC_SRR_NONE = 0;

    public const PROC_SRR_DONE = 1;

    public const PROC_CRC_NONE = 0;

    public const PROC_CRC_DONE = 1;

    // Constants for overall rename status
    public const IS_RENAMED_NONE = 0;

    public const IS_RENAMED_DONE = 1;

    /**
     * Has the current release found a new name?
     */
    public bool $matched;

    /**
     * How many releases have got a new name?
     */
    public int $fixed;

    /**
     * How many releases were checked.
     */
    public int $checked;

    /**
     * Was the check completed?
     */
    public bool $done;

    /**
     * Do we want to echo info to CLI?
     */
    public bool $echoOutput;

    /**
     * Total releases we are working on.
     */
    protected int $_totalReleases;

    /**
     * The cleaned filename we want to match.
     */
    protected string $_fileName;

    /**
     * The release ID we are trying to rename.
     */
    protected int $relid;

    protected string $othercats;

    protected string $timeother;

    protected string $timeall;

    protected string $fullother;

    protected string $fullall;

    public ColorCLI $colorCLI;

    /**
     * @var Categorize
     */
    public mixed $category;

    public Utility $text;

    /**
     * @var ManticoreSearch
     */
    public mixed $manticore;

    protected ColorCLI $colorCli;

    private ElasticSearchSiteSearch $elasticsearch;

    public function __construct()
    {
        $this->echoOutput = config('nntmux.echocli');
        $this->relid = $this->fixed = $this->checked = 0;
        $this->othercats = implode(',', Category::OTHERS_GROUP);
        $this->timeother = sprintf(' AND rel.adddate > (NOW() - INTERVAL 6 HOUR) AND rel.categories_id IN (%s) GROUP BY rel.id ORDER BY postdate DESC', $this->othercats);
        $this->timeall = ' AND rel.adddate > (NOW() - INTERVAL 6 HOUR) GROUP BY rel.id ORDER BY postdate DESC';
        $this->fullother = sprintf(' AND rel.categories_id IN (%s) GROUP BY rel.id', $this->othercats);
        $this->fullall = '';
        $this->_fileName = '';
        $this->done = $this->matched = false;
        $this->colorCLI = new ColorCLI;
        $this->category = new Categorize;
        $this->manticore = new ManticoreSearch;
        $this->elasticsearch = new ElasticSearchSiteSearch;
    }

    /**
     * Attempts to fix release names using the NFO.
     *
     *
     *
     * @throws \Exception
     */
    public function fixNamesWithNfo($time, $echo, $cats, $nameStatus, $show): void
    {
        $this->_echoStartMessage($time, '.nfo files');
        $type = 'NFO, ';

        // Only select releases we haven't checked here before
        $preId = false;
        if ($cats === 3) {
            $query = sprintf(
                '
					SELECT rel.id AS releases_id, rel.fromname
					FROM releases rel
					INNER JOIN release_nfos nfo ON (nfo.releases_id = rel.id)
					WHERE rel.nzbstatus = %d
					AND rel.predb_id = 0',
                NZB::NZB_ADDED
            );
            $cats = 2;
            $preId = true;
        } else {
            $query = sprintf(
                '
					SELECT rel.id AS releases_id, rel.fromname
					FROM releases rel
					INNER JOIN release_nfos nfo ON (nfo.releases_id = rel.id)
					WHERE (rel.isrenamed = %d OR rel.categories_id IN (%d, %d))
					AND rel.predb_id = 0
					AND rel.proc_nfo = %d',
                self::IS_RENAMED_NONE,
                Category::OTHER_MISC,
                Category::OTHER_HASHED,
                self::PROC_NFO_NONE
            );
        }

        $releases = $this->_getReleases($time, $cats, $query);

        $total = $releases->count();

        if ($total > 0) {
            $this->_totalReleases = $total;
            $this->colorCLI->climate()->info(number_format($total).' releases to process.');

            foreach ($releases as $rel) {
                $releaseRow = Release::fromQuery(
                    sprintf(
                        '
							SELECT nfo.releases_id AS nfoid, rel.groups_id, rel.fromname, rel.categories_id, rel.name, rel.searchname,
								UNCOMPRESS(nfo) AS textstring, rel.id AS releases_id
							FROM releases rel
							INNER JOIN release_nfos nfo ON (nfo.releases_id = rel.id)
							WHERE rel.id = %d LIMIT 1',
                        $rel->releases_id
                    )
                );

                $this->checked++;

                // Ignore encrypted NFOs.
                if (preg_match('/^=newz\[NZB\]=\w+/', $releaseRow[0]->textstring)) {
                    $this->_updateSingleColumn('proc_nfo', self::PROC_NFO_DONE, $rel->releases_id);

                    continue;
                }

                $this->reset();
                $this->checkName($releaseRow[0], $echo, $type, $nameStatus, $show, $preId);
                $this->_echoRenamed($show);
            }
            $this->_echoFoundCount($echo, ' NFO\'s');
        } else {
            $this->colorCLI->climate()->info('Nothing to fix.');
        }
    }

    /**
     * Attempts to fix release names using the File name.
     *
     *
     *
     * @throws \Exception
     */
    public function fixNamesWithFiles($time, $echo, $cats, $nameStatus, $show): void
    {
        $this->_echoStartMessage($time, 'file names');
        $type = 'Filenames, ';

        $preId = false;
        if ($cats === 3) {
            $query = sprintf(
                '
					SELECT rf.name AS textstring, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id,
						rf.releases_id AS fileid, rel.id AS releases_id
					FROM releases rel
					INNER JOIN release_files rf ON rf.releases_id = rel.id
					WHERE nzbstatus = %d
					AND predb_id = 0',
                NZB::NZB_ADDED
            );
            $cats = 2;
            $preId = true;
        } else {
            $query = sprintf(
                '
					SELECT rf.name AS textstring, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id,
						rf.releases_id AS fileid, rel.id AS releases_id
					FROM releases rel
					INNER JOIN release_files rf ON rf.releases_id = rel.id
					WHERE (rel.isrenamed = %d OR rel.categories_id IN (%d, %d))
					AND rel.predb_id = 0
					AND proc_files = %d',
                self::IS_RENAMED_NONE,
                Category::OTHER_MISC,
                Category::OTHER_HASHED,
                self::PROC_FILES_NONE
            );
        }

        $releases = $this->_getReleases($time, $cats, $query);
        $total = $releases->count();
        if ($total > 0) {
            $this->_totalReleases = $total;
            $this->colorCLI->climate()->info(number_format($total).' file names to process.');

            foreach ($releases as $release) {
                $this->reset();
                $this->checkName($release, $echo, $type, $nameStatus, $show, $preId);
                $this->checked++;
                $this->_echoRenamed($show);
            }

            $this->_echoFoundCount($echo, ' files');
        } else {
            $this->colorCLI->climate()->info('Nothing to fix.');
        }
    }

    /**
     * Attempts to fix release names using the rar file crc32 hash.
     *
     *
     *
     * @throws \Exception
     */
    public function fixNamesWithCrc($time, $echo, $cats, $nameStatus, $show): void
    {
        $this->_echoStartMessage($time, 'CRC32');
        $type = 'CRC32, ';

        $preId = false;
        if ($cats === 3) {
            $query = sprintf(
                '
					SELECT rf.crc32 AS textstring, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id, rel.size as relsize,
						rf.releases_id AS fileid, rel.id AS releases_id
					FROM releases rel
					INNER JOIN release_files rf ON rf.releases_id = rel.id
					WHERE nzbstatus = %d
					AND predb_id = 0',
                NZB::NZB_ADDED
            );
            $cats = 2;
            $preId = true;
        } else {
            $query = sprintf(
                '
					SELECT rf.crc32 AS textstring, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id, rel.size as relsize,
						rf.releases_id AS fileid, rel.id AS releases_id
					FROM releases rel
					INNER JOIN release_files rf ON rf.releases_id = rel.id
					WHERE (rel.isrenamed = %d OR rel.categories_id IN (%d, %d))
					AND rel.predb_id = 0
					AND rel.proc_crc32 = %d',
                self::IS_RENAMED_NONE,
                Category::OTHER_MISC,
                Category::OTHER_HASHED,
                self::PROC_CRC_NONE
            );
        }

        $releases = $this->_getReleases($time, $cats, $query);
        $total = $releases->count();
        if ($total > 0) {
            $this->_totalReleases = $total;
            $this->colorCLI->climate()->info(number_format($total).' CRC32\'s to process.');

            foreach ($releases as $release) {
                $this->reset();
                $this->checkName($release, $echo, $type, $nameStatus, $show, $preId);
                $this->checked++;
                $this->_echoRenamed($show);
            }

            $this->_echoFoundCount($echo, ' crc32\'s');
        } else {
            $this->colorCLI->climate()->info('Nothing to fix.');
        }
    }

    /**
     * Attempts to fix XXX release names using the File name.
     *
     *
     *
     * @throws \Exception
     */
    public function fixXXXNamesWithFiles($time, $echo, $cats, $nameStatus, $show): void
    {
        $this->_echoStartMessage($time, 'file names');
        $type = 'Filenames, ';

        if ($cats === 3) {
            $query = sprintf(
                '
					SELECT rf.name AS textstring, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id,
						rf.releases_id AS fileid, rel.id AS releases_id
					FROM releases rel
					INNER JOIN release_files rf ON rf.releases_id = rel.id
					WHERE nzbstatus = %d
					AND predb_id = 0',
                NZB::NZB_ADDED
            );
            $cats = 2;
        } else {
            $query = sprintf(
                '
					SELECT rf.name AS textstring, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id,
						rf.releases_id AS fileid, rel.id AS releases_id
					FROM releases rel
					INNER JOIN release_files rf ON rf.releases_id = rel.id
					WHERE (rel.isrenamed = %d OR rel.categories_id IN (%d, %d))
					AND rel.predb_id = 0
					AND rf.name LIKE %s',
                self::IS_RENAMED_NONE,
                Category::OTHER_MISC,
                Category::OTHER_HASHED,
                escapeString('%SDPORN%')
            );
        }

        $releases = $this->_getReleases($time, $cats, $query);
        $total = $releases->count();
        if ($total > 0) {
            $this->_totalReleases = $total;
            $this->colorCLI->climate()->info(number_format($total).' xxx file names to process.');

            foreach ($releases as $release) {
                $this->reset();
                $this->xxxNameCheck($release, $echo, $type, $nameStatus, $show);
                $this->checked++;
                $this->_echoRenamed($show);
            }
            $this->_echoFoundCount($echo, ' files');
        } else {
            $this->colorCLI->climate()->info('Nothing to fix.');
        }
    }

    /**
     * Attempts to fix release names using the SRR filename.
     *
     *
     *
     * @throws \Exception
     */
    public function fixNamesWithSrr($time, $echo, $cats, $nameStatus, $show): void
    {
        $this->_echoStartMessage($time, 'SRR file names');
        $type = 'SRR, ';

        if ($cats === 3) {
            $query = sprintf(
                '
					SELECT rf.name AS textstring, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id,
						rf.releases_id AS fileid, rel.id AS releases_id
					FROM releases rel
					INNER JOIN release_files rf ON rf.releases_id = rel.id
					WHERE nzbstatus = %d
					AND predb_id = 0',
                NZB::NZB_ADDED
            );
            $cats = 2;
        } else {
            $query = sprintf(
                '
					SELECT rf.name AS textstring, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id,
						rf.releases_id AS fileid, rel.id AS releases_id
					FROM releases rel
					INNER JOIN release_files rf ON rf.releases_id = rel.id
					WHERE (rel.isrenamed = %d OR rel.categories_id IN (%d, %d))
					AND rel.predb_id = 0
					AND rf.name LIKE %s
					AND rel.proc_srr = %d',
                self::IS_RENAMED_NONE,
                Category::OTHER_MISC,
                Category::OTHER_HASHED,
                escapeString('%.srr'),
                self::PROC_SRR_NONE
            );
        }

        $releases = $this->_getReleases($time, $cats, $query);
        $total = $releases->count();
        if ($total > 0) {
            $this->_totalReleases = $total;
            $this->colorCLI->climate()->info(number_format($total).' srr file extensions to process.');

            foreach ($releases as $release) {
                $this->reset();
                printf("Processing release id {$release->releases_id}..\r");
                $this->srrNameCheck($release, $echo, $type, $nameStatus, $show);
                $this->checked++;
                $this->_echoRenamed($show);
            }
            $this->_echoFoundCount($echo, ' files');
        } else {
            $this->colorCLI->climate()->info('Nothing to fix.');
        }
    }

    /**
     * Attempts to fix release names using the Par2 File.
     *
     * @param  $time  1: 24 hours, 2: no time limit
     * @param  $echo  1: change the name, anything else: preview of what could have been changed.
     * @param  $cats  1: other categories, 2: all categories
     *
     * @throws \Exception
     */
    public function fixNamesWithPar2($time, $echo, $cats, $nameStatus, $show, NNTP $nntp): void
    {
        $this->_echoStartMessage($time, 'par2 files');

        if ($cats === 3) {
            $query = sprintf(
                '
					SELECT rel.id AS releases_id, rel.guid, rel.groups_id, rel.fromname
					FROM releases rel
					WHERE rel.nzbstatus = %d
					AND rel.predb_id = 0',
                NZB::NZB_ADDED
            );
            $cats = 2;
        } else {
            $query = sprintf(
                '
					SELECT rel.id AS releases_id, rel.guid, rel.groups_id, rel.fromname
					FROM releases rel
					WHERE (rel.isrenamed = %d OR rel.categories_id IN (%d, %d))
					AND rel.predb_id = 0
					AND rel.proc_par2 = %d',
                self::IS_RENAMED_NONE,
                Category::OTHER_MISC,
                Category::OTHER_HASHED,
                self::PROC_PAR2_NONE
            );
        }

        $releases = $this->_getReleases($time, $cats, $query);

        $total = $releases->count();
        if ($total > 0) {
            $this->_totalReleases = $total;

            $this->colorCLI->climate()->info(number_format($total).' releases to process.');
            $Nfo = new Nfo;
            $nzbContents = new NZBContents;

            foreach ($releases as $release) {
                if ($nzbContents->checkPAR2($release->guid, $release->releases_id, $release->groups_id, $nameStatus, $show)) {
                    $this->fixed++;
                }

                $this->checked++;
                $this->_echoRenamed($show);
            }
            $this->_echoFoundCount($echo, ' files');
        } else {
            $this->colorCLI->climate()->info('Nothing to fix.');
        }
    }

    /**
     * @throws \Exception
     */
    public function fixNamesWithMedia($time, $echo, $cats, $nameStatus, $show): void
    {
        $type = 'UID, ';

        $this->_echoStartMessage($time, 'mediainfo Unique_IDs');

        // Re-check all releases we haven't matched to a PreDB
        if ($cats === 3) {
            $query = sprintf(
                '
				SELECT
					rel.id AS releases_id, rel.size AS relsize, rel.groups_id, rel.fromname, rel.categories_id,
					rel.name, rel.name AS textstring, rel.predb_id, rel.searchname,
					ru.unique_id AS uid
				FROM releases rel
				LEFT JOIN media_infos ru ON ru.releases_id = rel.id
				WHERE ru.releases_id IS NOT NULL
				AND rel.nzbstatus = %d
				AND rel.predb_id = 0',
                NZB::NZB_ADDED
            );
            $cats = 2;
            // Otherwise check only releases we haven't renamed and checked uid before in Misc categories
        } else {
            $query = sprintf(
                '
				SELECT
					rel.id AS releases_id, rel.size AS relsize, rel.groups_id, rel.fromname, rel.categories_id,
					rel.name, rel.name AS textstring, rel.predb_id, rel.searchname,
					ru.unique_id AS uid
				FROM releases rel
				LEFT JOIN media_infos ru ON ru.releases_id = rel.id
				WHERE ru.releases_id IS NOT NULL
				AND rel.nzbstatus = %d
				AND (rel.isrenamed = %d OR rel.categories_id IN (%d, %d))
				AND rel.predb_id = 0
				AND rel.proc_uid = %d',
                NZB::NZB_ADDED,
                self::IS_RENAMED_NONE,
                Category::OTHER_MISC,
                Category::OTHER_HASHED,
                self::PROC_UID_NONE
            );
        }

        $releases = $this->_getReleases($time, $cats, $query);
        $total = $releases->count();
        if ($total > 0) {
            $this->_totalReleases = $total;
            $this->colorCLI->climate()->info(number_format($total).' unique ids to process.');
            foreach ($releases as $rel) {
                $this->checked++;
                $this->reset();
                $this->uidCheck($rel, $echo, $type, $nameStatus, $show);
                $this->_echoRenamed($show);
            }
            $this->_echoFoundCount($echo, ' UID\'s');
        } else {
            $this->colorCLI->climate()->info('Nothing to fix.');
        }
    }

    /**
     * @throws \Exception
     */
    public function fixNamesWithMediaMovieName($time, $echo, $cats, $nameStatus, $show): void
    {
        $type = 'Mediainfo, ';

        $this->_echoStartMessage($time, 'Mediainfo movie_name');

        // Re-check all releases we haven't matched to a PreDB
        if ($cats === 3) {
            $query = sprintf(
                '
				SELECT rel.id AS releases_id, rel.name, rel.name AS textstring, rel.predb_id, rel.searchname, rel.fromname, rel.groups_id, rel.categories_id, rel.id AS releases_id, rf.movie_name as movie_name
				FROM releases rel
				INNER JOIN media_infos rf ON rf.releases_id = rel.id
                WHERE rel.nzbstatus = %d
                AND rel.predb_id = 0',
                NZB::NZB_ADDED
            );
            $cats = 2;
            // Otherwise check only releases we haven't renamed and checked uid before in Misc categories
        } else {
            $query = sprintf(
                '
				SELECT rel.id AS releases_id, rel.name, rel.name AS textstring, rel.predb_id, rel.searchname, rel.fromname, rel.groups_id, rel.categories_id, rel.id AS releases_id, rf.movie_name as movie_name, rf.file_name as file_name
				FROM releases rel
				INNER JOIN media_infos rf ON rf.releases_id = rel.id
				WHERE rel.nzbstatus = %d
                AND rel.isrenamed = %d
                AND rel.predb_id = 0',
                NZB::NZB_ADDED,
                self::IS_RENAMED_NONE
            );
            if ($cats === 2) {
                $query .= PHP_EOL.'AND rel.categories_id IN ('.Category::OTHER_MISC.','.Category::OTHER_HASHED.')';
            }
        }

        $releases = $this->_getReleases($time, $cats, $query);
        $total = $releases->count();
        if ($total > 0) {
            $this->_totalReleases = $total;
            $this->colorCLI->climate()->info(number_format($total).' mediainfo movie names to process.');
            foreach ($releases as $rel) {
                $this->checked++;
                $this->reset();
                $this->mediaMovieNameCheck($rel, $echo, $type, $nameStatus, $show);
                $this->_echoRenamed($show);
            }
            $this->_echoFoundCount($echo, ' MediaInfo\'s');
        } else {
            $this->colorCLI->climate()->info('Nothing to fix.');
        }
    }

    /**
     * Attempts to fix release names using the par2 hash_16K block.
     *
     *
     *
     * @throws \Exception
     */
    public function fixNamesWithParHash($time, $echo, $cats, $nameStatus, $show): void
    {
        $type = 'PAR2 hash, ';

        $this->_echoStartMessage($time, 'PAR2 hash_16K');

        // Re-check all releases we haven't matched to a PreDB
        if ($cats === 3) {
            $query = sprintf(
                '
				SELECT
					rel.id AS releases_id, rel.size AS relsize, rel.groups_id, rel.fromname, rel.categories_id,
					rel.name, rel.name AS textstring, rel.predb_id, rel.searchname,
					IFNULL(ph.hash, \'\') AS hash
				FROM releases rel
				LEFT JOIN par_hashes ph ON ph.releases_id = rel.id
				WHERE ph.hash != \'\'
				AND rel.nzbstatus = %d
				AND rel.predb_id = 0',
                NZB::NZB_ADDED
            );
            $cats = 2;
            // Otherwise check only releases we haven't renamed and checked their par2 hash_16K before in Misc categories
        } else {
            $query = sprintf(
                '
				SELECT
					rel.id AS releases_id, rel.size AS relsize, rel.groups_id, rel.fromname, rel.categories_id,
					rel.name, rel.name AS textstring, rel.predb_id, rel.searchname,
					IFNULL(ph.hash, \'\') AS hash
				FROM releases rel
				LEFT JOIN par_hashes ph ON ph.releases_id = rel.id
				WHERE rel.nzbstatus = %d
				AND (rel.isrenamed = %d OR rel.categories_id IN (%d, %d))
				AND rel.predb_id = 0
				AND ph.hash != \'\'
				AND rel.proc_hash16k = %d',
                NZB::NZB_ADDED,
                self::IS_RENAMED_NONE,
                Category::OTHER_MISC,
                Category::OTHER_HASHED,
                self::PROC_HASH16K_NONE
            );
        }

        $releases = $this->_getReleases($time, $cats, $query);

        $total = $releases->count();
        if ($total > 0) {
            $this->_totalReleases = $total;
            $this->colorCLI->climate()->info(number_format($total).' hash_16K to process.');
            foreach ($releases as $rel) {
                $this->checked++;
                $this->reset();
                $this->hashCheck($rel, $echo, $type, $nameStatus, $show);
                $this->_echoRenamed($show);
            }
            $this->_echoFoundCount($echo, ' hashes');
        } else {
            $this->colorCLI->climate()->info('Nothing to fix.');
        }
    }

    /**
     * @return false|\Illuminate\Database\Eloquent\Collection
     */
    protected function _getReleases($time, $cats, $query, int $limit = 0): \Illuminate\Database\Eloquent\Collection|bool
    {
        $releases = false;
        $queryLimit = ($limit === 0) ? '' : ' LIMIT '.$limit;
        // 24 hours, other cats
        if ($time === 1 && $cats === 1) {
            $releases = Release::fromQuery($query.$this->timeother.$queryLimit);
        } // 24 hours, all cats
        if ($time === 1 && $cats === 2) {
            $releases = Release::fromQuery($query.$this->timeall.$queryLimit);
        } //other cats
        if ($time === 2 && $cats === 1) {
            $releases = Release::fromQuery($query.$this->fullother.$queryLimit);
        } // all cats
        if ($time === 2 && $cats === 2) {
            $releases = Release::fromQuery($query.$this->fullall.$queryLimit);
        }

        return $releases;
    }

    /**
     * Echo the amount of releases that found a new name.
     *
     * @param  bool|int  $echo  1: change the name, anything else: preview of what could have been changed.
     * @param  string  $type  The function type that found the name.
     */
    protected function _echoFoundCount(bool|int $echo, string $type): void
    {
        if ($echo === true) {
            $this->colorCLI->climate()->info(
                PHP_EOL.
                number_format($this->fixed).
                ' releases have had their names changed out of: '.
                number_format($this->checked).
                $type.'.'
            );
        } else {
            $this->colorCLI->climate()->info(
                PHP_EOL.
                number_format($this->fixed).
                ' releases could have their names changed. '.
                number_format($this->checked).
                $type.' were checked.'
            );
        }
    }

    /**
     * @param  int  $time  1: 24 hours, 2: no time limit
     * @param  string  $type  The function type.
     */
    protected function _echoStartMessage(int $time, string $type): void
    {
        $this->colorCLI->climate()->info(
            sprintf(
                'Fixing search names %s using %s.',
                ($time === 1 ? 'in the past 6 hours' : 'since the beginning'),
                $type
            )
        );
    }

    protected function _echoRenamed(int $show): void
    {
        if ($this->checked % 500 === 0 && $show === 1) {
            $this->colorCLI->alternate(PHP_EOL.number_format($this->checked).' files processed.'.PHP_EOL);
        }

        if ($show === 2) {
            $this->colorCLI->climate()->info(
                'Renamed Releases: ['.
                number_format($this->fixed).
                '] '.
                (new ConsoleTools)->percentString($this->checked, $this->_totalReleases)
            );
        }
    }

    /**
     * Update the release with the new information.
     *
     *
     *
     * @throws \Exception
     */
    public function updateRelease($release, $name, $method, $echo, $type, $nameStatus, $show, $preId = 0): void
    {
        if (\is_array($release)) {
            $release = (object) $release;
        }
        // If $release does not have a releases_id, we should add it.
        if (! isset($release->releases_id)) {
            $release->releases_id = $release->id;
        }
        if ($this->relid !== $release->releases_id) {
            $newName = (new ReleaseCleaning)->fixerCleaner($name);
            if (strtolower($newName) !== strtolower($release->searchname)) {
                $this->matched = true;
                $this->relid = (int) $release->releases_id;

                $determinedCategory = $this->category->determineCategory($release->groups_id, $newName, ! empty($release->fromname) ? $release->fromname : '');

                if ($type === 'PAR2, ') {
                    $newName = ucwords($newName);
                    if (preg_match('/(.+?)\.[a-z0-9]{2,3}(PAR2)?$/i', $name, $hit)) {
                        $newName = $hit[1];
                    }
                }

                $this->fixed++;

                $newName = explode('\\', $newName);
                $newName = preg_replace(['/^[=_\.:\s-]+/', '/[=_\.:\s-]+$/'], '', $newName[0]);

                if ($this->echoOutput && $show) {
                    $groupName = UsenetGroup::getNameByID($release->groups_id);
                    $oldCatName = Category::getNameByID($release->categories_id);
                    $newCatName = Category::getNameByID($determinedCategory['categories_id']);

                    if ($type === 'PAR2, ') {
                        echo PHP_EOL;
                    }

                    echo PHP_EOL;

                    $this->colorCLI->headerOver('New name:  ').
                        $this->colorCLI->info(substr($newName, 0, 299)).
                        $this->colorCLI->headerOver('Old name:  ').
                        $this->colorCLI->info($release->searchname).
                        $this->colorCLI->headerOver('Use name:  ').
                        $this->colorCLI->info($release->name).
                        $this->colorCLI->headerOver('New cat:   ').
                        $this->colorCLI->info($newCatName).
                        $this->colorCLI->headerOver('Old cat:   ').
                        $this->colorCLI->info($oldCatName).
                        $this->colorCLI->headerOver('Group:     ').
                        $this->colorCLI->info($groupName).
                        $this->colorCLI->headerOver('Method:    ').
                        $this->colorCLI->info($type.$method).
                        $this->colorCLI->headerOver('Releases ID: ').
                        $this->colorCLI->info($release->releases_id);
                    if (! empty($release->filename)) {
                        $this->colorCLI->headerOver('Filename:  ').
                            $this->colorCLI->info($release->filename);
                    }

                    if ($type !== 'PAR2, ') {
                        echo PHP_EOL;
                    }
                }

                $newTitle = substr($newName, 0, 299);

                if ($echo === true) {
                    if ($nameStatus === true) {
                        $status = '';
                        switch ($type) {
                            case 'NFO, ':
                                $status = ['isrenamed' => 1, 'iscategorized' => 1, 'proc_nfo' => 1];
                                break;
                            case 'PAR2, ':
                                $status = ['isrenamed' => 1, 'iscategorized' => 1, 'proc_par2' => 1];
                                break;
                            case 'Filenames, ':
                            case 'file matched source: ':
                                $status = ['isrenamed' => 1, 'iscategorized' => 1, 'proc_files' => 1];
                                break;
                            case 'SHA1, ':
                            case 'MD5, ':
                                $status = ['isrenamed' => 1, 'iscategorized' => 1, 'dehashstatus' => 1];
                                break;
                            case 'PreDB FT Exact, ':
                                $status = ['isrenamed' => 1, 'iscategorized' => 1];
                                break;
                            case 'sorter, ':
                                $status = ['isrenamed' => 1, 'iscategorized' => 1, 'proc_sorter' => 1];
                                break;
                            case 'UID, ':
                            case 'Mediainfo, ':
                                $status = ['isrenamed' => 1, 'iscategorized' => 1, 'proc_uid' => 1];
                                break;
                            case 'PAR2 hash, ':
                                $status = ['isrenamed' => 1, 'iscategorized' => 1, 'proc_hash16k' => 1];
                                break;
                            case 'SRR, ':
                                $status = ['isrenamed' => 1, 'iscategorized' => 1, 'proc_srr' => 1];
                                break;
                            case 'CRC32, ':
                                $status = ['isrenamed' => 1, 'iscategorized' => 1, 'proc_crc32' => 1];
                                break;
                        }

                        $updateColumns = [
                            'videos_id' => 0,
                            'tv_episodes_id' => 0,
                            'imdbid' => '',
                            'musicinfo_id' => '',
                            'consoleinfo_id' => '',
                            'bookinfo_id' => '',
                            'anidbid' => '',
                            'predb_id' => $preId,
                            'searchname' => $newTitle,
                            'categories_id' => $determinedCategory['categories_id'],
                        ];

                        if ($status !== '') {
                            foreach ($status as $key => $stat) {
                                $updateColumns = Arr::add($updateColumns, $key, $stat);
                            }
                        }

                        Release::query()
                            ->where('id', $release->releases_id)
                            ->update($updateColumns);

                        if (config('nntmux.elasticsearch_enabled') === true) {
                            $this->elasticsearch->updateRelease($release->releases_id);
                        } else {
                            $this->manticore->updateRelease($release->releases_id);
                        }
                    } else {
                        Release::query()
                            ->where('id', $release->releases_id)
                            ->update(
                                [
                                    'videos_id' => 0,
                                    'tv_episodes_id' => 0,
                                    'imdbid' => null,
                                    'musicinfo_id' => null,
                                    'consoleinfo_id' => null,
                                    'bookinfo_id' => null,
                                    'anidbid' => null,
                                    'predb_id' => $preId,
                                    'searchname' => $newTitle,
                                    'categories_id' => $determinedCategory['categories_id'],
                                    'iscategorized' => 1,
                                ]
                            );

                        if (config('nntmux.elasticsearch_enabled') === true) {
                            $this->elasticsearch->updateRelease($release->releases_id);
                        } else {
                            $this->manticore->updateRelease($release->releases_id);
                        }
                    }
                }
            }
        }
        $this->done = true;
    }

    /**
     * Echo an updated release name to CLI.
     *
     *
     * @static
     *
     * @void
     */
    public static function echoChangedReleaseName(
        array $data =
        [
            'new_name' => '',
            'old_name' => '',
            'new_category' => '',
            'old_category' => '',
            'group' => '',
            'releases_id' => 0,
            'method' => '',
        ]
    ): void {
        $colorCLI = new ColorCLI;
        echo PHP_EOL;

        $colorCLI->primaryOver('Old name:     ').$colorCLI->info($data['old_name']).
            $colorCLI->primaryOver('New name:     ').$colorCLI->info($data['new_name']).
            $colorCLI->primaryOver('Old category: ').$colorCLI->info(Category::getNameByID($data['old_category'])).
            $colorCLI->primaryOver('New category: ').$colorCLI->info(Category::getNameByID($data['new_category'])).
            $colorCLI->primaryOver('Group:        ').$colorCLI->info($data['group']).
            $colorCLI->primaryOver('Releases ID:   ').$colorCLI->info($data['releases_id']).
            $colorCLI->primaryOver('Method:       ').$colorCLI->info($data['method']);
    }

    /**
     * Match a PreDB title to a release name or searchname using an exact full-text match.
     *
     *
     * @throws \Exception
     */
    public function matchPredbFT($pre, $echo, $nameStatus, $show): int
    {
        $matching = $total = 0;

        $join = $this->_preFTsearchQuery($pre['title']);

        if ($join === '') {
            return $matching;
        }

        //Find release matches with fulltext and then identify exact matches with cleaned LIKE string
        $res = Release::fromQuery(
            sprintf(
                '
				SELECT r.id AS releases_id, r.name, r.searchname,
				r.fromname, r.groups_id, r.categories_id
				FROM releases r
				WHERE r.id IN (%s)
				AND r.predb_id = 0',
                $join
            )
        );

        if ($res->isNotEmpty()) {
            $total = $res->count();
        }

        // Run if row count is positive, but do not run if row count exceeds 10 (as this is likely a failed title match)
        if ($total > 0 && $total <= 15) {
            foreach ($res as $row) {
                if ($pre['title'] !== $row->searchname) {
                    $this->updateRelease($row, $pre['title'], 'Title Match source: '.$pre['source'], $echo, 'PreDB FT Exact, ', $nameStatus, $show, $pre['predb_id']);
                    $matching++;
                }
            }
        } elseif ($total >= 16) {
            $matching = -1;
        }

        return $matching;
    }

    protected function _preFTsearchQuery($preTitle): string
    {
        $join = '';

        if (\strlen($preTitle) >= 15 && preg_match(self::PREDB_REGEX, $preTitle)) {
            $titleMatch = $this->manticore->searchIndexes('releases_rt', $preTitle, ['name', 'searchname', 'filename']);
            if (! empty($titleMatch)) {
                $join = implode(',', Arr::get($titleMatch, 'id'));
            }
        }

        return $join;
    }

    /**
     * Retrieves releases and their file names to attempt PreDB matches
     * Runs in a limited mode based on arguments passed or a full mode broken into chunks of entire DB.
     *
     * @param  array  $args  The CLI script arguments
     *
     * @throws \Exception
     */
    public function getPreFileNames(array $args = []): void
    {
        $show = isset($args[2]) && $args[2] === 'show';

        if (isset($args[1]) && is_numeric($args[1])) {
            $limit = 'LIMIT '.$args[1];
            $orderBy = 'ORDER BY r.id DESC';
        } else {
            $orderBy = 'ORDER BY r.id ASC';
            $limit = 'LIMIT 1000000';
        }

        $this->colorCLI->climate()->info(PHP_EOL.'Match PreFiles '.$args[1].' Started at '.now());
        $this->colorCLI->climate()->info('Matching predb filename to cleaned release_files.name.');

        $counter = $counted = 0;
        $timeStart = now();

        $query = Release::fromQuery(
            sprintf(
                "
					SELECT r.id AS releases_id, r.name, r.searchname,
						r.fromname, r.groups_id, r.categories_id,
						GROUP_CONCAT(rf.name ORDER BY LENGTH(rf.name) DESC SEPARATOR '||') AS filename
					FROM releases r
					INNER JOIN release_files rf ON r.id = rf.releases_id
					WHERE rf.name IS NOT NULL
					AND r.predb_id = 0
					AND r.categories_id IN (%s)
					AND r.isrenamed = 0
					GROUP BY r.id
					%s %s",
                implode(',', Category::OTHERS_GROUP),
                $orderBy,
                $limit
            )
        );

        if ($query->isNotEmpty()) {
            $total = $query->count();

            if ($total > 0) {
                $this->colorCLI->climate()->info(PHP_EOL.number_format($total).' releases to process.');

                foreach ($query as $row) {
                    $success = $this->matchPreDbFiles($row, true, 1, $show);
                    if ($success === 1) {
                        $counted++;
                    }
                    if ($show === 0) {
                        $this->colorCLI->climate()->info('Renamed Releases: ['.number_format($counted).'] '.(new ConsoleTools)->percentString(++$counter, $total));
                    }
                }
                $this->colorCLI->climate()->info(PHP_EOL.'Renamed '.number_format($counted).' releases in '.now()->diffInSeconds($timeStart, true).' seconds'.'.');
            } else {
                $this->colorCLI->climate()->info('Nothing to do.');
            }
        }
    }

    /**
     * Match a release filename to a PreDB filename or title.
     *
     *
     * @throws \Exception
     */
    public function matchPreDbFiles($release, bool $echo, $nameStatus, bool $show): int
    {
        $matching = 0;

        foreach (explode('||', $release->filename) as $key => $fileName) {
            $this->_fileName = $fileName;
            $this->_cleanMatchFiles();
            $preMatch = $this->preMatch($this->_fileName);
            if ($preMatch[0] === true) {
                if (config('nntmux.elasticsearch_enabled') === true) {
                    $results = $this->elasticsearch->searchPreDb($preMatch[1]);
                } else {
                    $results = Arr::get($this->manticore->searchIndexes('predb_rt', $preMatch[1], ['filename', 'title']), 'data');
                }
                if (! empty($results)) {
                    foreach ($results as $result) {
                        if (! empty($result)) {
                            $preFtMatch = $this->preMatch($result['filename']);
                            if ($preFtMatch[0] === true) {
                                $this->_fileName = $result['filename'];
                                $release->filename = $this->_fileName;
                                if ($result['title'] !== $release->searchname) {
                                    $this->updateRelease($release, $result['title'], 'file matched source: '.$result['source'], $echo, 'PreDB file match, ', $nameStatus, $show);
                                } else {
                                    $this->_updateSingleColumn('predb_id', $result['id'], $release->releases_id);
                                }
                                $matching++;
                                break;
                            }
                        }
                    }
                }
            }
        }

        return $matching;
    }

    /**
     * Cleans file names for PreDB Match.
     */
    protected function _cleanMatchFiles(): string
    {
        // first strip all non-printing chars  from filename
        $this->_fileName = str_replace('/[[:^print:]]/', '', $this->_fileName);

        if ($this->_fileName !== '' && ! str_starts_with($this->_fileName, '.')) {
            $this->_fileName = match (true) {
                str_contains($this->_fileName, '.') => Str::beforeLast('.', $this->_fileName),
                preg_match('/\.part\d+$/', $this->_fileName) => Str::beforeLast('.', $this->_fileName),
                preg_match('/\.vol\d+(\+\d+)?$/', $this->_fileName) => Str::beforeLast('.', $this->_fileName),
                str_contains($this->_fileName, '\\') => Str::afterLast('\\', $this->_fileName),
                preg_match('/^\d{2}-/', $this->_fileName) => preg_replace('/^\d{2}-/', '', $this->_fileName),
                default => trim($this->_fileName),
            };

            return trim($this->_fileName);
        }

        return false;
    }

    /**
     * Match a Hash from the predb to a release.
     *
     *
     * @throws \Exception
     */
    public function matchPredbHash(string $hash, $release, $echo, $nameStatus, $show): int
    {
        $matching = 0;
        $this->matched = false;

        // Determine MD5 or SHA1
        if (\strlen($hash) === 40) {
            $hashtype = 'SHA1, ';
        } else {
            $hashtype = 'MD5, ';
        }

        $row = Predb::fromQuery(
            sprintf(
                '
						SELECT p.id AS predb_id, p.title, p.source
						FROM predb p INNER JOIN predb_hashes h ON h.predb_id = p.id
						WHERE h.hash = UNHEX(%s)
						LIMIT 1',
                escapeString($hash)
            )
        );

        foreach ($row as $item) {
            if (! empty($item)) {
                if ($item->title !== $release->searchname) {
                    $this->updateRelease($release, $item->title, 'predb hash release name: '.$item->source, $echo, $hashtype, $nameStatus, $show, $item->predb_id);
                    $matching++;
                }
            } else {
                $this->_updateSingleColumn('dehashstatus', $release->dehashstatus - 1, $release->releases_id);
            }
        }

        return $matching;
    }

    /**
     * Matches the hashes within the predb table to release files and subjects (names) which are hashed.
     *
     *
     * @throws \Exception
     */
    public function parseTitles($time, $echo, $cats, $nameStatus, $show): int
    {
        $updated = $checked = 0;

        $tq = '';
        if ($time === 1) {
            $tq = 'AND r.adddate > (NOW() - INTERVAL 3 HOUR) ORDER BY rf.releases_id, rf.size DESC';
        }
        $ct = '';
        if ($cats === 1) {
            $ct = sprintf('AND r.categories_id IN (%s)', $this->othercats);
        }

        if ($this->echoOutput) {
            $te = '';
            if ($time === 1) {
                $te = ' in the past 3 hours';
            }
            $this->colorCLI->climate()->info('Fixing search names'.$te.' using the predb hash.');
        }
        $regex = 'AND (r.ishashed = 1 OR rf.ishashed = 1)';

        if ($cats === 3) {
            $query = sprintf('SELECT r.id AS releases_id, r.name, r.searchname, r.categories_id, r.groups_id, '
                .'dehashstatus, rf.name AS filename FROM releases r '
                .'LEFT OUTER JOIN release_files rf ON r.id = rf.releases_id '
                .'WHERE nzbstatus = 1 AND dehashstatus BETWEEN -6 AND 0 AND predb_id = 0 %s', $regex);
        } else {
            $query = sprintf('SELECT r.id AS releases_id, r.name, r.searchname, r.categories_id, r.groups_id, '
                .'dehashstatus, rf.name AS filename FROM releases r '
                .'LEFT OUTER JOIN release_files rf ON r.id = rf.releases_id '
                .'WHERE nzbstatus = 1 AND isrenamed = 0 AND dehashstatus BETWEEN -6 AND 0 %s %s %s', $regex, $ct, $tq);
        }

        $res = Release::fromQuery($query);
        $total = $res->count();
        $this->colorCLI->climate()->info(number_format($total).' releases to process.');
        foreach ($res as $row) {
            if (preg_match('/[a-fA-F0-9]{32,40}/i', $row->name, $hits)) {
                $updated += $this->matchPredbHash($hits[0], $row, $echo, $nameStatus, $show);
            } elseif (preg_match('/[a-fA-F0-9]{32,40}/i', $row->filename, $hits)) {
                $updated += $this->matchPredbHash($hits[0], $row, $echo, $nameStatus, $show);
            }
            if ($show === 2) {
                $this->colorCLI->climate()->info('Renamed Releases: ['.number_format($updated).'] '.(new ConsoleTools)->percentString($checked++, $total));
            }
        }
        if ($echo === 1) {
            $this->colorCLI->climate()->info(PHP_EOL.$updated.' releases have had their names changed out of: '.number_format($checked).' files.');
        } else {
            $this->colorCLI->climate()->info(PHP_EOL.$updated.' releases could have their names changed. '.number_format($checked).' files were checked.');
        }

        return $updated;
    }

    /**
     * Check the array using regex for a clean name.
     *
     *
     * @throws \Exception
     */
    public function checkName($release, bool $echo, string $type, $nameStatus, bool $show, bool $preId = false): bool
    {
        // Get pre style name from releases.name
        if (preg_match_all(self::PREDB_REGEX, $release->textstring, $hits) && ! preg_match('/Source\s\:/i', $release->textstring)) {
            foreach ($hits as $hit) {
                foreach ($hit as $val) {
                    $title = Predb::query()->where('title', trim($val))->select(['title', 'id'])->first();
                    if ($title !== null) {
                        $this->updateRelease($release, $title['title'], 'preDB: Match', $echo, $type, $nameStatus, $show, $title['id']);
                        $preId = true;
                    }
                }
            }
        }

        // if only processing for PreDB match skip to return
        if (! $preId) {
            switch ($type) {
                case 'PAR2, ':
                    $this->fileCheck($release, $echo, $type, $nameStatus, $show);
                    break;
                case 'PAR2 hash, ':
                    $this->hashCheck($release, $echo, $type, $nameStatus, $show);
                    break;
                case 'UID, ':
                    $this->uidCheck($release, $echo, $type, $nameStatus, $show);
                    break;
                case 'Mediainfo, ':
                    $this->mediaMovieNameCheck($release, $echo, $type, $nameStatus, $show);
                    break;
                case 'SRR, ':
                    $this->srrNameCheck($release, $echo, $type, $nameStatus, $show);
                    break;
                case 'CRC32, ':
                    $this->crcCheck($release, $echo, $type, $nameStatus, $show);
                    break;
                case 'NFO, ':
                    $this->nfoCheckTV($release, $echo, $type, $nameStatus, $show);
                    $this->nfoCheckMov($release, $echo, $type, $nameStatus, $show);
                    $this->nfoCheckMus($release, $echo, $type, $nameStatus, $show);
                    $this->nfoCheckTY($release, $echo, $type, $nameStatus, $show);
                    $this->nfoCheckG($release, $echo, $type, $nameStatus, $show);
                    break;
                case 'Filenames, ':
                    $this->preDbFileCheck($release, $echo, $type, $nameStatus, $show);
                    $this->preDbTitleCheck($release, $echo, $type, $nameStatus, $show);
                    $this->fileCheck($release, $echo, $type, $nameStatus, $show);
                    break;
                default:
                    $this->tvCheck($release, $echo, $type, $nameStatus, $show);
                    $this->movieCheck($release, $echo, $type, $nameStatus, $show);
                    $this->gameCheck($release, $echo, $type, $nameStatus, $show);
                    $this->appCheck($release, $echo, $type, $nameStatus, $show);
            }

            // set NameFixer process flags after run
            if ($nameStatus === true && ! $this->matched) {
                switch ($type) {
                    case 'NFO, ':
                        $this->_updateSingleColumn('proc_nfo', self::PROC_NFO_DONE, $release->releases_id);
                        break;
                    case 'Filenames, ':
                        $this->_updateSingleColumn('proc_files', self::PROC_FILES_DONE, $release->releases_id);
                        break;
                    case 'PAR2, ':
                        $this->_updateSingleColumn('proc_par2', self::PROC_PAR2_DONE, $release->releases_id);
                        break;
                    case 'PAR2 hash, ':
                        $this->_updateSingleColumn('proc_hash16k', self::PROC_HASH16K_DONE, $release->releases_id);
                        break;
                    case 'SRR, ':
                        $this->_updateSingleColumn('proc_srr', self::PROC_SRR_DONE, $release->releases_id);
                        break;
                    case 'UID, ':
                        $this->_updateSingleColumn('proc_uid', self::PROC_UID_DONE, $release->releases_id);
                        break;
                }
            }
        }

        return $this->matched;
    }

    /**
     * This function updates a single variable column in releases
     *  The first parameter is the column to update, the second is the value
     *  The final parameter is the ID of the release to update.
     */
    public function _updateSingleColumn($column = '', $status = 0, $id = 0): void
    {
        if ((string) $column !== '' && (int) $id !== 0) {
            Release::query()->where('id', $id)->update([$column => $status]);
        }
    }

    /**
     * Look for a TV name.
     *
     *
     * @throws \Exception
     */
    public function tvCheck($release, bool $echo, string $type, $nameStatus, $show): void
    {
        $result = [];

        if (! $this->done && $this->relid !== (int) $release->releases_id) {
            if (preg_match('/\w[\-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|(?<!\d)[S|]\d{1,2}[E|x]\d{1,}(?!\d)|ep[._ -]?\d{2})[\-\w.\',;.()]+(BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -][\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'tvCheck: Title.SxxExx.Text.source.group', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/\w[\-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[\-\w.\',;& ]+((19|20)\d\d)[\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'tvCheck: Title.SxxExx.Text.year.group', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/\w[\-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[\-\w.\',;& ]+(480|720|1080)[ip][._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'tvCheck: Title.SxxExx.Text.resolution.source.vcodec.group', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/\w[\-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'tvCheck: Title.SxxExx.source.vcodec.group', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/\w[\-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](480|720|1080)[ip][._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'tvCheck: Title.SxxExx.acodec.source.res.vcodec.group', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/\w[\-\w.\',;& ]+((19|20)\d\d)[._ -]((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'tvCheck: Title.year.###(season/episode).source.group', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/\w(19|20)\d\d[._ -]\d{2}[._ -]\d{2}[._ -](IndyCar|NBA|NCW([TY])S|NNS|NSCS?)([._ -](19|20)\d\d)?[\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'tvCheck: Sports', $echo, $type, $nameStatus, $show);
            }
        }
    }

    /**
     * Look for a movie name.
     *
     *
     * @throws \Exception
     */
    public function movieCheck($release, bool $echo, string $type, $nameStatus, $show): void
    {
        $result = [];

        if (! $this->done && $this->relid !== (int) $release->releases_id) {
            if (preg_match('/\w[\-\w.\',;& ]+((19|20)\d\d)[\-\w.\',;& ]+(480|720|1080)[ip][._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'movieCheck: Title.year.Text.res.vcod.group', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/\w[\-\w.\',;& ]+((19|20)\d\d)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[._ -](480|720|1080)[ip][\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'movieCheck: Title.year.source.vcodec.res.group', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/\w[\-\w.\',;& ]+((19|20)\d\d)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'movieCheck: Title.year.source.vcodec.acodec.group', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/\w[\-\w.\',;& ]+(Brazilian|Chinese|Croatian|Danish|Deutsch|Dutch|Estonian|English|Finnish|Flemish|Francais|French|German|Greek|Hebrew|Icelandic|Italian|Japenese|Japan|Japanese|Korean|Latin|Nordic|Norwegian|Polish|Portuguese|Russian|Serbian|Slovenian|Swedish|Spanisch|Spanish|Thai|Turkish)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'movieCheck: Title.year.language.acodec.source.vcodec.group', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/\w[\-\w.\',;& ]+((19|20)\d\d)[._ -](480|720|1080)[ip][._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'movieCheck: Title.year.resolution.source.acodec.vcodec.group', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/\w[\-\w.\',;& ]+((19|20)\d\d)[._ -](480|720|1080)[ip][._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'movieCheck: Title.year.resolution.source.vcodec.group', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/\w[\-\w.\',;& ]+((19|20)\d\d)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](480|720|1080)[ip][._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'movieCheck: Title.year.source.resolution.acodec.vcodec.group', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/\w[\-\w.\',;& ]+((19|20)\d\d)[._ -](480|720|1080)[ip][._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'movieCheck: Title.year.resolution.acodec.vcodec.group', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/[\-\w.\',;& ]+((19|20)\d\d)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BR(RIP)?|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](480|720|1080)[ip][._ -][\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'movieCheck: Title.year.source.res.group', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/\w[\-\w.\',;& ]+((19|20)\d\d)[._ -][\-\w.\',;& ]+[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BR(RIP)?|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'movieCheck: Title.year.eptitle.source.vcodec.group', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/\w[\-\w.\',;& ]+(480|720|1080)[ip][._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'movieCheck: Title.resolution.source.acodec.vcodec.group', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/\w[\-\w.\',;& ]+(480|720|1080)[ip][._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[\-\w.\',;& ]+(BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -]((19|20)\d\d)[\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'movieCheck: Title.resolution.acodec.eptitle.source.year.group', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/\w[\-\w.\',;& ]+(Brazilian|Chinese|Croatian|Danish|Deutsch|Dutch|Estonian|English|Finnish|Flemish|Francais|French|German|Greek|Hebrew|Icelandic|Italian|Japenese|Japan|Japanese|Korean|Latin|Nordic|Norwegian|Polish|Portuguese|Russian|Serbian|Slovenian|Swedish|Spanisch|Spanish|Thai|Turkish)[._ -]((19|20)\d\d)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'movieCheck: Title.language.year.acodec.src', $echo, $type, $nameStatus, $show);
            }
        }
    }

    /**
     * Look for a game name.
     *
     *
     * @throws \Exception
     */
    public function gameCheck($release, bool $echo, string $type, $nameStatus, $show): void
    {
        $result = [];

        if (! $this->done && $this->relid !== (int) $release->releases_id) {
            if (preg_match('/\w[\-\w.\',;& ]+(ASIA|DLC|EUR|GOTY|JPN|KOR|MULTI\d{1}|NTSCU?|PAL|RF|Region[._ -]?Free|USA|XBLA)[._ -](DLC[._ -]Complete|FRENCH|GERMAN|MULTI\d{1}|PROPER|PSN|READ[._ -]?NFO|UMD)?[._ -]?(GC|NDS|NGC|PS3|PSP|WII|XBOX(360)?)[\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'gameCheck: Videogames 1', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/\w[\-\w.\',;& ]+(GC|NDS|NGC|PS3|WII|XBOX(360)?)[._ -](DUPLEX|iNSOMNi|OneUp|STRANGE|SWAG|SKY)[\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'gameCheck: Videogames 2', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/\w[\w.\',;-].+-OUTLAWS/i', $release->textstring, $result)) {
                $result = str_replace('OUTLAWS', 'PC GAME OUTLAWS', $result['0']);
                $this->updateRelease($release, $result['0'], 'gameCheck: PC Games -OUTLAWS', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/\w[\w.\',;-].+\-ALiAS/i', $release->textstring, $result)) {
                $newResult = str_replace('-ALiAS', ' PC GAME ALiAS', $result['0']);
                $this->updateRelease($release, $newResult, 'gameCheck: PC Games -ALiAS', $echo, $type, $nameStatus, $show);
            }
        }
    }

    /**
     * Look for a app name.
     *
     *
     * @throws \Exception
     */
    public function appCheck($release, bool $echo, string $type, $nameStatus, $show): void
    {
        $result = [];

        if (! $this->done && $this->relid !== (int) $release->releases_id) {
            if (preg_match('/\w[\-\w.\',;& ]+(\d{1,10}|Linux|UNIX)[._ -](RPM)?[._ -]?(X64)?[._ -]?(Incl)[._ -](Keygen)[\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'appCheck: Apps 1', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/\w[\-\w.\',;& ]+\d{1,8}[._ -](winall-freeware)[\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['0'], 'appCheck: Apps 2', $echo, $type, $nameStatus, $show);
            }
        }
    }

    /**
     * TV.
     *
     *
     * @throws \Exception
     */
    public function nfoCheckTV($release, bool $echo, string $type, $nameStatus, $show): void
    {
        $result = [];

        if (! $this->done && $this->relid !== (int) $release->releases_id) {
            if (preg_match('/:\s*.*[\\\\\/]([A-Z0-9].+?S\d+[.-_ ]?[ED]\d+.+?)\.\w{2,}\s+/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['1'], 'nfoCheck: Generic TV 1', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/(?:(\:\s{1,}))(.+?S\d{1,3}[.-_ ]?[ED]\d{1,3}.+?)(\s{2,}|\r|\n)/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['2'], 'nfoCheck: Generic TV 2', $echo, $type, $nameStatus, $show);
            }
        }
    }

    /**
     * Movies.
     *
     *
     * @throws \Exception
     */
    public function nfoCheckMov($release, bool $echo, string $type, $nameStatus, $show): void
    {
        $result = [];

        if (! $this->done && $this->relid !== (int) $release->releases_id) {
            if (preg_match('/(?:((?!Source\s)\:\s{1,}))(.+?(19|20)\d\d.+?(BDRip|bluray|DVD(R|Rip)?|XVID).+?)(\s{2,}|\r|\n)/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['2'], 'nfoCheck: Generic Movies 1', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/(?:(\s{2,}))((?!Source).+?[\.\-_ ](19|20)\d\d.+?(BDRip|bluray|DVD(R|Rip)?|XVID).+?)(\s{2,}|\r|\n)/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['2'], 'nfoCheck: Generic Movies 2', $echo, $type, $nameStatus, $show);
            } elseif (preg_match('/(?:(\s{2,}))(.+?[\.\-_ ](NTSC|MULTi).+?(MULTi|DVDR)[\.\-_ ].+?)(\s{2,}|\r|\n)/i', $release->textstring, $result)) {
                $this->updateRelease($release, $result['2'], 'nfoCheck: Generic Movies 3', $echo, $type, $nameStatus, $show);
            }
        }
    }

    /**
     * @throws \Exception
     */
    public function nfoCheckMus($release, bool $echo, string $type, $nameStatus, $show): void
    {
        $result = [];

        if (! $this->done && $this->relid !== (int) $release->releases_id && preg_match('/(?:\s{2,})(.+?-FM-\d{2}-\d{2})/i', $release->textstring, $result)) {
            $newName = str_replace('-FM-', '-FM-Radio-MP3-', $result['1']);
            $this->updateRelease($release, $newName, 'nfoCheck: Music FM RADIO', $echo, $type, $nameStatus, $show);
        }
    }

    /**
     * Title (year).
     *
     *
     * @throws \Exception
     */
    public function nfoCheckTY($release, bool $echo, string $type, $nameStatus, $show): void
    {
        $result = [];

        if (! $this->done && $this->relid !== (int) $release->releases_id && preg_match('/(\w[\-\w`~!@#$%^&*()_+={}|"<>?\[\]\\;\',.\/ ]+\s?\((19|20)\d\d\))/i', $release->textstring, $result) && ! preg_match('/\.pdf|Audio ?Book/i', $release->textstring)) {
            $releaseName = $result[0];
            if (preg_match('/(idiomas|lang|language|langue|sprache).*?\b(?P<lang>Brazilian|Chinese|Croatian|Danish|DE|Deutsch|Dutch|Estonian|ES|English|Englisch|Finnish|Flemish|Francais|French|FR|German|Greek|Hebrew|Icelandic|Italian|Japenese|Japan|Japanese|Korean|Latin|Nordic|Norwegian|Polish|Portuguese|Russian|Serbian|Slovenian|Swedish|Spanisch|Spanish|Thai|Turkish)\b/i', $release->textstring, $result)) {
                switch ($result['lang']) {
                    case 'DE':
                        $result['lang'] = 'GERMAN';
                        break;
                    case 'Englisch':
                        $result['lang'] = 'ENGLISH';
                        break;
                    case 'FR':
                        $result['lang'] = 'FRENCH';
                        break;
                    case 'ES':
                        $result['lang'] = 'SPANISH';
                        break;
                    default:
                        break;
                }
                $releaseName .= '.'.$result['lang'];
            }

            if (preg_match('/(frame size|(video )?res(olution)?|video).*?(?P<res>(272|336|480|494|528|608|\(?640|688|704|720x480|810|816|820|1 ?080|1280( \@)?|1 ?920(x1080)?))/i', $release->textstring, $result)) {
                switch ($result['res']) {
                    case '272':
                    case '336':
                    case '480':
                    case '494':
                    case '608':
                    case '640':
                    case '(640':
                    case '688':
                    case '704':
                    case '720x480':
                        $result['res'] = '480p';
                        break;
                    case '1280x720':
                    case '1280':
                    case '1280 @':
                        $result['res'] = '720p';
                        break;
                    case '810':
                    case '816':
                    case '820':
                    case '1920':
                    case '1 920':
                    case '1080':
                    case '1 080':
                    case '1920x1080':
                        $result['res'] = '1080p';
                        break;
                    case '2160':
                        $result['res'] = '2160p';
                        break;
                }

                $releaseName .= '.'.$result['res'];
            } elseif (preg_match('/(largeur|width).*?(?P<res>(\(?640|688|704|720|1280( \@)?|1 ?920))/i', $release->textstring, $result)) {
                switch ($result['res']) {
                    case '640':
                    case '(640':
                    case '688':
                    case '704':
                    case '720':
                        $result['res'] = '480p';
                        break;
                    case '1280 @':
                    case '1280':
                        $result['res'] = '720p';
                        break;
                    case '1920':
                    case '1 920':
                        $result['res'] = '1080p';
                        break;
                    case '2160':
                        $result['res'] = '2160p';
                        break;
                }

                $releaseName .= '.'.$result['res'];
            }

            if (preg_match('/source.*?\b(?P<source>BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)\b/i', $release->textstring, $result)) {
                switch ($result['source']) {
                    case 'BD':
                        $result['source'] = 'Bluray.x264';
                        break;
                    case 'CAMRIP':
                        $result['source'] = 'CAM';
                        break;
                    case 'DBrip':
                        $result['source'] = 'BDRIP';
                        break;
                    case 'DVD R1':
                    case 'NTSC':
                    case 'PAL':
                    case 'VOD':
                        $result['source'] = 'DVD';
                        break;
                    case 'HD':
                        $result['source'] = 'HDTV';
                        break;
                    case 'Ripped ':
                        $result['source'] = 'DVDRIP';
                }

                $releaseName .= '.'.$result['source'];
            } elseif (preg_match('/(codec( (name|code))?|(original )?format|res(olution)|video( (codec|format|res))?|tv system|type|writing library).*?\b(?P<video>AVC|AVI|DBrip|DIVX|\(Divx|DVD|[HX][._ -]?264|MPEG-4 Visual|NTSC|PAL|WMV|XVID)\b/i', $release->textstring, $result)) {
                switch ($result['video']) {
                    case 'AVI':
                        $result['video'] = 'DVDRIP';
                        break;
                    case 'DBrip':
                        $result['video'] = 'BDRIP';
                        break;
                    case '(Divx':
                        $result['video'] = 'DIVX';
                        break;
                    case 'h264':
                    case 'h-264':
                    case 'h.264':
                        $result['video'] = 'H264';
                        break;
                    case 'MPEG-4 Visual':
                    case 'x264':
                    case 'x-264':
                    case 'x.264':
                        $result['video'] = 'x264';
                        break;
                    case 'NTSC':
                    case 'PAL':
                        $result['video'] = 'DVD';
                        break;
                }

                $releaseName .= '.'.$result['video'];
            }

            if (preg_match('/(audio( format)?|codec( name)?|format).*?\b(?P<audio>0x0055 MPEG-1 Layer 3|AAC( LC)?|AC-?3|\(AC3|DD5(.1)?|(A_)?DTS-?(HD)?|Dolby(\s?TrueHD)?|TrueHD|FLAC|MP3)\b/i', $release->textstring, $result)) {
                switch ($result['audio']) {
                    case '0x0055 MPEG-1 Layer 3':
                        $result['audio'] = 'MP3';
                        break;
                    case 'AC-3':
                    case '(AC3':
                        $result['audio'] = 'AC3';
                        break;
                    case 'AAC LC':
                        $result['audio'] = 'AAC';
                        break;
                    case 'A_DTS':
                    case 'DTS-HD':
                    case 'DTSHD':
                        $result['audio'] = 'DTS';
                }
                $releaseName .= '.'.$result['audio'];
            }
            $releaseName .= '-NoGroup';
            $this->updateRelease($release, $releaseName, 'nfoCheck: Title (Year)', $echo, $type, $nameStatus, $show);
        }
    }

    /**
     * Games.
     *
     *
     * @throws \Exception
     */
    public function nfoCheckG($release, bool $echo, string $type, $nameStatus, $show): void
    {
        $result = [];

        if (! $this->done && $this->relid !== (int) $release->releases_id) {
            if (preg_match('/ALiAS|BAT-TEAM|FAiRLiGHT|Game Type|Glamoury|HI2U|iTWINS|JAGUAR|(LARGE|MEDIUM)ISO|MAZE|nERv|PROPHET|PROFiT|PROCYON|RELOADED|REVOLVER|ROGUE|ViTALiTY/i', $release->textstring)) {
                if (preg_match('/\w[\w.+&*\/\()\',;: -]+\(c\)[\-\w.\',;& ]+\w/i', $release->textstring, $result)) {
                    $releaseName = str_replace(['(c)', '(C)'], '(GAMES) (c)', $result['0']);
                    $this->updateRelease($release, $releaseName, 'nfoCheck: PC Games (c)', $echo, $type, $nameStatus, $show);
                } elseif (preg_match('/\w[\w.+&*\/()\',;: -]+\*ISO\*/i', $release->textstring, $result)) {
                    $releaseName = str_replace('*ISO*', '*ISO* (PC GAMES)', $result['0']);
                    $this->updateRelease($release, $releaseName, 'nfoCheck: PC Games *ISO*', $echo, $type, $nameStatus, $show);
                }
            }
        }
    }

    //

    /**
     * Misc.
     *
     *
     * @throws \Exception
     */
    public function nfoCheckMisc($release, bool $echo, string $type, $nameStatus, $show): void
    {
        if (! $this->done && $this->relid !== (int) $release->releases_id) {
            if (preg_match('/Supplier.+?IGUANA/i', $release->textstring)) {
                $releaseName = '';
                $result = [];
                if (preg_match('/\w[\-\w`~!@#$%^&*()+={}|:"<>?\[\]\\;\',.\/ ]+\s\((19|20)\d\d\)/i', $release->textstring, $result)) {
                    $releaseName = $result[0];
                } elseif (preg_match('/\s\[\*\] (English|Dutch|French|German|Spanish)\b/i', $release->textstring, $result)) {
                    $releaseName .= '.'.$result[1];
                } elseif (preg_match('/\s\[\*\] (DT?S [2567][._ -][0-2]( MONO)?)\b/i', $release->textstring, $result)) {
                    $releaseName .= '.'.$result[2];
                } elseif (preg_match('/Format.+(DVD([59R])?|[HX][._ -]?264)\b/i', $release->textstring, $result)) {
                    $releaseName .= '.'.$result[1];
                } elseif (preg_match('/\[(640x.+|1280x.+|1920x.+)\] Resolution\b/i', $release->textstring, $result)) {
                    if ($result[1] === '640x.+') {
                        $result[1] = '480p';
                    } elseif ($result[1] === '1280x.+') {
                        $result[1] = '720p';
                    } elseif ($result[1] === '1920x.+') {
                        $result[1] = '1080p';
                    }
                    $releaseName .= '.'.$result[1];
                }
                $result = $releaseName.'.IGUANA';
                $this->updateRelease($release, $result, 'nfoCheck: IGUANA', $echo, $type, $nameStatus, $show);
            }
        }
    }

    /**
     * Just for filenames.
     *
     *
     * @throws \Exception
     */
    public function fileCheck($release, bool $echo, string $type, $nameStatus, $show): bool
    {
        $result = [];

        if (! $this->done && $this->relid !== (int) $release->releases_id) {
            switch (true) {
                case preg_match('/^(.+?(x264|XviD)\-TVP)\\\\/i', $release->textstring, $result):
                    $this->updateRelease($release, $result['1'], 'fileCheck: TVP', $echo, $type, $nameStatus, $show);
                    break;
                case preg_match('/^(\\\\|\/)?(.+(\\\\|\/))*(.+?S\d{1,3}[.-_ ]?[ED]\d{1,3}.+)\.(.+)$/iu', $release->textstring, $result):
                    $this->updateRelease($release, $result['4'], 'fileCheck: Generic TV', $echo, $type, $nameStatus, $show);
                    break;
                case preg_match('/^(\\\\|\/)?(.+(\\\\|\/))*(.+?([\.\-_ ]\d{4}[\.\-_ ].+?(BDRip|bluray|DVDRip|XVID)).+)\.(.+)$/iu', $release->textstring, $result):
                    $this->updateRelease($release, $result['4'], 'fileCheck: Generic movie 1', $echo, $type, $nameStatus, $show);
                    break;
                case preg_match('/^([a-z0-9\.\-_]+(19|20)\d\d[a-z0-9\.\-_]+[\.\-_ ](720p|1080p|BDRip|bluray|DVDRip|x264|XviD)[a-z0-9\.\-_]+)\.[a-z]{2,}$/i', $release->textstring, $result):
                    $this->updateRelease($release, $result['1'], 'fileCheck: Generic movie 2', $echo, $type, $nameStatus, $show);
                    break;
                case preg_match('/(.+?([\.\-_ ](CD|FM)|[\.\-_ ]\dCD|CDR|FLAC|SAT|WEB).+?(19|20)\d\d.+?)\\\\.+/i', $release->textstring, $result):
                    $this->updateRelease($release, $result['1'], 'fileCheck: Generic music', $echo, $type, $nameStatus, $show);
                    break;
                case preg_match('/^(.+?(19|20)\d\d\-([a-z0-9]{3}|[a-z]{2,}|C4))\\\\/i', $release->textstring, $result):
                    $this->updateRelease($release, $result['1'], 'fileCheck: music groups', $echo, $type, $nameStatus, $show);
                    break;
                case preg_match('/.+\\\\(.+\((19|20)\d\d\)\.avi)$/i', $release->textstring, $result):
                    $newName = str_replace('.avi', ' DVDRip XVID NoGroup', $result['1']);
                    $this->updateRelease($release, $newName, 'fileCheck: Movie (year) avi', $echo, $type, $nameStatus, $show);
                    break;
                case preg_match('/.+\\\\(.+\((19|20)\d\d\)\.iso)$/i', $release->textstring, $result):
                    $newName = str_replace('.iso', ' DVD NoGroup', $result['1']);
                    $this->updateRelease($release, $newName, 'fileCheck: Movie (year) iso', $echo, $type, $nameStatus, $show);
                    break;
                case preg_match('/^(.+?IMAGESET.+?)\\\\.+/i', $release->textstring, $result):
                    $this->updateRelease($release, $result['1'], 'fileCheck: XXX Imagesets', $echo, $type, $nameStatus, $show);
                    break;
                case preg_match('/^VIDEOOT-[A-Z0-9]+\\\\([\w!.,& ()\[\]\'\`-]{8,}?\b.?)([\-_](proof|sample|thumbs?))*(\.part\d*(\.rar)?|\.rar|\.7z)?(\d{1,3}\.rev|\.vol.+?|\.mp4)/', $release->textstring, $result):
                    $this->updateRelease($release, $result['1'].' XXX DVDRIP XviD-VIDEOOT', 'fileCheck: XXX XviD VIDEOOT', $echo, $type, $nameStatus, $show);
                    break;
                case preg_match('/^.+?SDPORN/i', $release->textstring, $result):
                    $this->updateRelease($release, $result['0'], 'fileCheck: XXX SDPORN', $echo, $type, $nameStatus, $show);
                    break;
                case preg_match('/\w[\-\w.\',;& ]+1080i[._ -]DD5[._ -]1[._ -]MPEG2-R&C(?=\.ts)$/i', $release->textstring, $result):
                    $result = str_replace('MPEG2', 'MPEG2.HDTV', $result['0']);
                    $this->updateRelease($release, $result, 'fileCheck: R&C', $echo, $type, $nameStatus, $show);
                    break;
                case preg_match('/\w[\-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](480|720|1080)[ip][._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -]nSD[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[._ -]NhaNC3[\-\w.\',;& ]+\w/i', $release->textstring, $result):
                    $this->updateRelease($release, $result['0'], 'fileCheck: NhaNc3', $echo, $type, $nameStatus, $show);
                    break;
                case preg_match('/\wtvp-[\w.\-\',;]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](720p|1080p|xvid)(?=\.(avi|mkv))$/i', $release->textstring, $result):
                    $result = str_replace('720p', '720p.HDTV.X264', $result['0']);
                    $result = str_replace('1080p', '1080p.Bluray.X264', $result['0']);
                    $result = str_replace('xvid', 'XVID.DVDrip', $result['0']);
                    $this->updateRelease($release, $result, 'fileCheck: tvp', $echo, $type, $nameStatus, $show);
                    break;
                case preg_match('/\w[\-\w.\',;& ]+\d{3,4}\.hdtv-lol\.(avi|mp4|mkv|ts|nfo|nzb)/i', $release->textstring, $result):
                    $this->updateRelease($release, $result['0'], 'fileCheck: Title.211.hdtv-lol.extension', $echo, $type, $nameStatus, $show);
                    break;
                case preg_match('/\w[\-\w.\',;& ]+-S\d{1,2}[EX]\d{1,2}-XVID-DL.avi/i', $release->textstring, $result):
                    $this->updateRelease($release, $result['0'], 'fileCheck: Title-SxxExx-XVID-DL.avi', $echo, $type, $nameStatus, $show);
                    break;
                case preg_match('/\S.*[\w.\-\',;]+\s\-\ss\d{2}[ex]\d{2}\s\-\s[\w.\-\',;].+\./i', $release->textstring, $result):
                    $this->updateRelease($release, $result['0'], 'fileCheck: Title - SxxExx - Eptitle', $echo, $type, $nameStatus, $show);
                    break;
                case preg_match('/\w.+?\)\.nds$/i', $release->textstring, $result):
                    $this->updateRelease($release, $result['0'], 'fileCheck: ).nds Nintendo DS', $echo, $type, $nameStatus, $show);
                    break;
                case preg_match('/3DS_\d{4}.+\d{4} - (.+?)\.3ds/i', $release->textstring, $result):
                    $this->updateRelease($release, '3DS '.$result['1'], 'fileCheck: .3ds Nintendo 3DS', $echo, $type, $nameStatus, $show);
                    break;
                case preg_match('/\w.+?\.(epub|mobi|azw|opf|fb2|prc|djvu|cb[rz])/i', $release->textstring, $result):
                    $result = str_replace('.'.$result['1'], ' ('.$result['1'].')', $result['0']);
                    $this->updateRelease($release, $result, 'fileCheck: EBook', $echo, $type, $nameStatus, $show);
                    break;
                case preg_match('/\w+[\-\w.\',;& ]+$/i', $release->textstring, $result) && preg_match(self::PREDB_REGEX, $release->textstring):
                    $this->updateRelease($release, $result['0'], 'fileCheck: Folder name', $echo, $type, $nameStatus, $show);
                    break;
                default:
                    return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Look for a name based on mediainfo xml Unique_ID.
     *
     *
     *
     * @throws \Exception
     */
    public function uidCheck($release, $echo, $type, $nameStatus, $show): bool
    {
        if (! empty($release->uid) && ! $this->done && $this->relid !== (int) $release->releases_id) {
            $result = Release::fromQuery(sprintf(
                '
				SELECT r.id AS releases_id, r.size AS relsize, r.name AS textstring, r.searchname, r.fromname, r.predb_id
				FROM releases r
				LEFT JOIN media_infos ru ON ru.releases_id = r.id
				WHERE ru.releases_id IS NOT NULL
				AND ru.unique_id = %s
				AND ru.releases_id != %d
				AND (r.predb_id > 0 OR r.anidbid > 0 OR r.fromname = %s)',
                escapeString($release->uid),
                $release->releases_id,
                escapeString('nonscene@Ef.net (EF)')
            ));

            foreach ($result as $res) {
                $floor = round(($res['relsize'] - $release->relsize) / $res['relsize'] * 100, 1);
                if ($floor >= -10 && $floor <= 10) {
                    $this->updateRelease(
                        $release,
                        $res->searchname,
                        'uidCheck: Unique_ID',
                        $echo,
                        $type,
                        $nameStatus,
                        $show,
                        $res->predb_id
                    );

                    return true;
                }
            }
        }
        $this->_updateSingleColumn('proc_uid', self::PROC_UID_DONE, $release->releases_id);

        return false;
    }

    /**
     * Look for a name based on mediainfo xml Unique_ID.
     *
     *
     *
     * @throws \Exception
     */
    public function mediaMovieNameCheck($release, $echo, $type, $nameStatus, $show): bool
    {
        $newName = '';
        if (! $this->done && $this->relid !== (int) $release->releases_id) {
            if (! empty($release->movie_name)) {
                if (preg_match(self::PREDB_REGEX, $release->movie_name, $hit)) {
                    $newName = $hit[1];
                } elseif (preg_match('/(.+),(\sRMZ\.cr)?$/i', $release->movie_name, $hit)) {
                    $newName = $hit[1];
                } else {
                    $newName = $release->movie_name;
                }
            }

            if ($newName !== '') {
                $this->updateRelease($release, $newName, 'MediaInfo: Movie Name', $echo, $type, $nameStatus, $show, $release->predb_id);

                return true;
            }
        }
        $this->_updateSingleColumn('proc_uid', self::PROC_UID_DONE, $release->releases_id);

        return false;
    }

    /**
     * Look for a name based on xxx release filename.
     *
     *
     *
     * @throws \Exception
     */
    public function xxxNameCheck($release, $echo, $type, $nameStatus, $show): bool
    {
        if (! $this->done && $this->relid !== (int) $release->releases_id) {
            $result = Release::fromQuery(
                sprintf(
                    "
				SELECT rf.name AS textstring, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id,
						rf.releases_id AS fileid, rel.id AS releases_id
					FROM releases rel
					INNER JOIN release_files rf ON (rf.releases_id = {$release->releases_id})
					WHERE (rel.isrenamed = %d OR rel.categories_id IN(%d, %d))
					AND rf.name LIKE %s",
                    self::IS_RENAMED_NONE,
                    Category::OTHER_MISC,
                    Category::OTHER_HASHED,
                    escapeString('%SDPORN%')
                )
            );

            foreach ($result as $res) {
                if (preg_match('/^.+?SDPORN/i', $res->textstring, $hit)) {
                    $this->updateRelease(
                        $release,
                        $hit['0'],
                        'fileCheck: XXX SDPORN',
                        $echo,
                        $type,
                        $nameStatus,
                        $show
                    );

                    return true;
                }
            }
        }
        $this->_updateSingleColumn('proc_files', self::PROC_FILES_DONE, $release->releases_id);

        return false;
    }

    /**
     * Look for a name based on .srr release files extension.
     *
     *
     *
     * @throws \Exception
     */
    public function srrNameCheck($release, $echo, $type, $nameStatus, $show): bool
    {
        if (! $this->done && $this->relid !== (int) $release->releases_id) {
            $result = Release::fromQuery(
                sprintf(
                    "
				    SELECT rf.name AS textstring, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id,
						rf.releases_id AS fileid, rel.id AS releases_id
					FROM releases rel
					INNER JOIN release_files rf ON (rf.releases_id = {$release->releases_id})
					WHERE (rel.isrenamed = %d OR rel.categories_id IN (%d, %d))
					AND rf.name LIKE %s",
                    self::IS_RENAMED_NONE,
                    Category::OTHER_MISC,
                    Category::OTHER_HASHED,
                    escapeString('%.srr')
                )
            );

            foreach ($result as $res) {
                if (preg_match('/^(.*)\.srr$/i', $res->textstring, $hit)) {
                    $this->updateRelease(
                        $release,
                        $hit['1'],
                        'fileCheck: SRR extension',
                        $echo,
                        $type,
                        $nameStatus,
                        $show
                    );

                    return true;
                }
            }
        }
        $this->_updateSingleColumn('proc_srr', self::PROC_SRR_DONE, $release->releases_id);

        return false;
    }

    /**
     * Look for a name based on par2 hash_16K block.
     *
     *
     *
     * @throws \Exception
     */
    public function hashCheck($release, $echo, $type, $nameStatus, $show): bool
    {
        if (! $this->done && $this->relid !== (int) $release->releases_id) {
            $result = Release::fromQuery(sprintf(
                '
				SELECT r.id AS releases_id, r.size AS relsize, r.name AS textstring, r.searchname, r.fromname, r.predb_id
				FROM releases r
				LEFT JOIN par_hashes ph ON ph.releases_id = r.id
				WHERE ph.hash = %s
				AND ph.releases_id != %d
				AND (r.predb_id > 0 OR r.anidbid > 0)',
                escapeString($release->hash),
                $release->releases_id
            ));

            foreach ($result as $res) {
                $floor = round(($res->relsize - $release->relsize) / $res->relsize * 100, 1);
                if ($floor >= -5 && $floor <= 5) {
                    $this->updateRelease(
                        $release,
                        $res->searchname,
                        'hashCheck: PAR2 hash_16K',
                        $echo,
                        $type,
                        $nameStatus,
                        $show,
                        $res->predb_id
                    );

                    return true;
                }
            }
        }
        $this->_updateSingleColumn('proc_hash16k', self::PROC_HASH16K_DONE, $release->releases_id);

        return false;
    }

    /**
     * Look for a name based on rar crc32 hash.
     *
     *
     *
     * @throws \Exception
     */
    public function crcCheck($release, $echo, $type, $nameStatus, $show): bool
    {
        if (! $this->done && $this->relid !== (int) $release->releases_id && $release->textstring !== '') {
            $result = Release::fromQuery(
                sprintf(
                    '
				    SELECT rf.crc32, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id, rel.size as relsize, rel.predb_id as predb_id,
						rf.releases_id AS fileid, rel.id AS releases_id
					FROM releases rel
					LEFT JOIN release_files rf ON rf.releases_id = rel.id
					WHERE rel.predb_id > 0
					AND rf.crc32 = %s',
                    escapeString($release->textstring)
                )
            );

            foreach ($result as $res) {
                $floor = round(($res->relsize - $release->relsize) / $res->relsize * 100, 1);
                if ($floor >= -5 && $floor <= 5) {
                    $this->updateRelease(
                        $release,
                        $res->searchname,
                        'crcCheck: CRC32',
                        $echo,
                        $type,
                        $nameStatus,
                        $show,
                        $res->predb_id
                    );

                    return true;
                }
            }
        }
        $this->_updateSingleColumn('proc_crc32', self::PROC_CRC_DONE, $release->releases_id);

        return false;
    }

    /**
     * Resets NameFixer status variables for new processing.
     */
    public function reset(): void
    {
        $this->done = $this->matched = false;
    }

    private function preMatch(string $fileName): array
    {
        $result = preg_match('/(\d{2}\.\d{2}\.\d{2})+([\w\-.]+[\w]$)/i', $fileName, $hit);

        return [$result === 1, $hit[0] ?? ''];
    }

    /**
     * @throws \Exception
     */
    public function preDbFileCheck($release, bool $echo, string $type, $nameStatus, bool $show): bool
    {
        $this->_fileName = $release->textstring;
        $this->_cleanMatchFiles();
        $this->cleanFileNames();
        if (! empty($this->_fileName)) {
            if (config('nntmux.elasticsearch_enabled') === true) {
                $results = $this->elasticsearch->searchPreDb($this->_fileName);
                foreach ($results as $hit) {
                    if (! empty($hit)) {
                        $this->updateRelease($release, $hit['title'], 'PreDb: Filename match', $echo, $type, $nameStatus, $show, $hit['id']);

                        return true;
                    }
                }
            } else {
                $predbSearch = Arr::get($this->manticore->searchIndexes('predb_rt', $this->_fileName, ['filename', 'title']), 'data');
                if (! empty($predbSearch)) {
                    foreach ($predbSearch as $hit) {
                        if (! empty($hit)) {
                            $this->updateRelease($release, $hit['title'], 'PreDb: Filename match', $echo, $type, $nameStatus, $show);

                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * @throws \Exception
     */
    public function preDbTitleCheck($release, bool $echo, string $type, $nameStatus, bool $show): bool
    {
        $this->_fileName = $release->textstring;
        $this->_cleanMatchFiles();
        $this->cleanFileNames();
        if (! empty($this->_fileName)) {
            if (config('nntmux.elasticsearch_enabled') === true) {
                $results = $this->elasticsearch->searchPreDb($this->_fileName);
                foreach ($results as $hit) {
                    if (! empty($hit)) {
                        $this->updateRelease($release, $hit['title'], 'PreDb: Title match', $echo, $type, $nameStatus, $show, $hit['id']);

                        return true;
                    }
                }
            } else {
                $results = Arr::get($this->manticore->searchIndexes('predb_rt', $this->_fileName, ['title']), 'data');
                if (! empty($results)) {
                    foreach ($results as $hit) {
                        if (! empty($hit)) {
                            $this->updateRelease($release, $hit['title'], 'PreDb: Title match', $echo, $type, $nameStatus, $show);

                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    private function cleanFileNames(): array|string|null
    {
        if (preg_match('/(\.[a-zA-Z]{2})?(\.4k|\.fullhd|\.hd|\.int|\.\d+)?$/i', $this->_fileName, $hit)) {
            if (! empty($hit[1]) && preg_match('/\.[a-zA-Z]{2}/i', $hit[1])) {
                $this->_fileName = preg_replace('/\.[a-zA-Z]{2}\./i', '.', $this->_fileName);
            }
            if (! empty($hit[2])) {
                if (preg_match('/\.4k$/', $hit[2])) {
                    $this->_fileName = preg_replace('/\.4k$/', '.2160p', $this->_fileName);
                }
                if (preg_match('/\.fullhd$/i', $hit[2])) {
                    $this->_fileName = preg_replace('/\.fullhd$/i', '.1080p', $this->_fileName);
                }
                if (preg_match('/\.hd$/i', $hit[2])) {
                    $this->_fileName = preg_replace('/\.hd$/i', '.720p', $this->_fileName);
                }
                if (preg_match('/\.int$/i', $hit[2])) {
                    $this->_fileName = preg_replace('/\.int$/i', '.INTERNAL', $this->_fileName);
                }
                if (preg_match('/\.\d+/', $hit[2])) {
                    $this->_fileName = preg_replace('/\.\d+$/', '', $this->_fileName);
                }
            }
            if (preg_match('/^[a-zA-Z]{0,7}\./', $this->_fileName)) {
                $this->_fileName = preg_replace('/^[a-zA-Z]{0,7}\./', '', $this->_fileName);
            }
        }

        return $this->_fileName;
    }

    /**
     * @return string|string[]
     */
    private function escapeString($string): array|string
    {
        $from = ['+', '-', '=', '&&', '|', '!', '(', ')', '{', '}', '[', ']', '^', '"', '~', '*', '?', ':', '\\', '/'];
        $to = ["\+", "\-", "\=", "\&&", "\|", "\!", "\(", "\)", "\{", "\}", "\[", "\]", "\^", '\"', "\~", "\*", "\?", "\:", '\\\\', "\/"];

        return str_replace($from, $to, $string);
    }
}
