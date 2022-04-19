<?php

namespace Blacklight;

use App\Models\Binary;
use App\Models\Collection;
use App\Models\Part;
use App\Models\Release;
use App\Models\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Class for reading and writing NZB files on the hard disk,
 * building folder paths to store the NZB files.
 *
 *
 * Class NZB
 */
class NZB
{
    public const NZB_NONE = 0; // Release has no NZB file yet.
    public const NZB_ADDED = 1; // Release had an NZB file created.

    protected const NZB_DTD_NAME = 'nzb';
    protected const NZB_DTD_PUBLIC = '-//newzBin//DTD NZB 1.1//EN';
    protected const NZB_DTD_EXTERNAL = 'http://www.newzbin.com/DTD/nzb/nzb-1.1.dtd';
    protected const NZB_XML_NS = 'http://www.newzbin.com/DTD/2003/nzb';

    /**
     * Levels deep to store NZB files.
     *
     * @var int
     */
    protected $nzbSplitLevel;

    /**
     * Path to store NZB files.
     *
     * @var string
     */
    protected $siteNzbPath;

    /**
     * Group id when writing NZBs.
     *
     * @var int
     */
    protected $groupID;

    /**
     * @var \PDO
     */
    public $pdo;

    /**
     * @var bool
     */
    protected $_debug = false;

    /**
     * Base query for selecting collection data for writing NZB files.
     *
     * @var string
     */
    protected $_collectionsQuery;

    /**
     * Base query for selecting binary data for writing NZB files.
     *
     * @var string
     */
    protected $_binariesQuery;

    /**
     * Base query for selecting parts data for writing NZB files.
     *
     * @var string
     */
    protected $_partsQuery;

    /**
     * String used for head in NZB XML file.
     *
     * @var string
     */
    protected $_nzbCommentString;

    /**
     * Names of CBP tables.
     *
     * @var array [string => string]
     */
    protected $_tableNames;

    /**
     * @var string
     */
    protected $_siteCommentString;

    /**
     * NZB constructor.
     */
    public function __construct()
    {
        $nzbSplitLevel = (int) Settings::settingValue('..nzbsplitlevel');
        $this->nzbSplitLevel = $nzbSplitLevel ?? 1;
        $this->siteNzbPath = (string) Settings::settingValue('..nzbpath');
        if (! Str::endsWith($this->siteNzbPath, '/')) {
            $this->siteNzbPath .= '/';
        }
        $this->_nzbCommentString = sprintf(
            'NZB Generated by: NNTmux %s',
            now()->format('F j, Y, g:i a O')
        );
        $this->_siteCommentString = sprintf(
            'NZB downloaded from %s',
            config('app.name')
        );
    }

    /**
     * Write an NZB to the hard drive for a single release.
     *
     *
     *
     * @param  \App\Models\Release  $release
     * @return bool
     *
     * @throws \Throwable
     */
    public function writeNzbForReleaseId(Release $release): bool
    {
        $collections = Collection::whereReleasesId($release->id)
            ->join('usenet_groups', 'collections.groups_id', '=', 'usenet_groups.id')
            ->select(['collections.*', DB::raw('UNIX_TIMESTAMP(collections.date) AS udate'), 'usenet_groups.name as groupname'])
            ->get();

        if (empty($collections)) {
            return false;
        }

        $XMLWriter = new \XMLWriter();
        $XMLWriter->openMemory();
        $XMLWriter->setIndent(true);
        $XMLWriter->setIndentString('  ');

        $nzb_guid = '';

        $XMLWriter->startDocument('1.0', 'UTF-8');
        $XMLWriter->startDtd(self::NZB_DTD_NAME, self::NZB_DTD_PUBLIC, self::NZB_DTD_EXTERNAL);
        $XMLWriter->endDtd();
        $XMLWriter->writeComment($this->_nzbCommentString);

        $XMLWriter->startElement('nzb');
        $XMLWriter->writeAttribute('xmlns', self::NZB_XML_NS);
        $XMLWriter->startElement('head');
        $XMLWriter->startElement('meta');
        $XMLWriter->writeAttribute('type', 'category');
        $XMLWriter->text(! empty($release->category->parent) ? $release->category->parent->title.' >'.$release->category->title : 'Other > Misc');
        $XMLWriter->endElement();
        $XMLWriter->startElement('meta');
        $XMLWriter->writeAttribute('type', 'name');
        $XMLWriter->text($release->name);
        $XMLWriter->endElement();
        $XMLWriter->endElement(); //head

        foreach ($collections as $collection) {
            $binaries = Binary::whereCollectionsId($collection->id)->select(['id', 'name', 'totalparts'])->orderBy('name')->get();
            if (empty($binaries)) {
                return false;
            }

            $poster = $collection->fromname;

            foreach ($binaries as $binary) {
                $parts = Part::whereBinariesId($binary->id)->distinct()->select(['messageid', 'size', 'partnumber'])->orderBy('partnumber')->get();
                if (empty($parts)) {
                    return false;
                }

                $subject = $binary->name.'(1/'.$binary->totalparts.')';
                $XMLWriter->startElement('file');
                $XMLWriter->writeAttribute('poster', $poster);
                $XMLWriter->writeAttribute('date', $collection->udate);
                $XMLWriter->writeAttribute('subject', $subject);
                $XMLWriter->startElement('groups');
                if (preg_match_all('#(\S+):\S+#', $collection->xref, $hits)) {
                    $hits = array_values(array_unique($hits[1]));
                    foreach ($hits as $group) {
                        $XMLWriter->writeElement('group', $group);
                    }
                } elseif (preg_match_all('#(\S+)#', $collection->xref, $hits)) {
                    $hits = array_values(array_unique($hits[1]));
                    foreach ($hits as $group) {
                        $XMLWriter->writeElement('group', $group);
                    }
                } else {
                    return false;
                }
                $XMLWriter->endElement(); //groups
                $XMLWriter->startElement('segments');
                foreach ($parts as $part) {
                    if ($nzb_guid === '') {
                        $nzb_guid = $part->messageid;
                    }
                    $XMLWriter->startElement('segment');
                    $XMLWriter->writeAttribute('bytes', $part->size);
                    $XMLWriter->writeAttribute('number', $part->partnumber);
                    $XMLWriter->text($part->messageid);
                    $XMLWriter->endElement();
                }
                $XMLWriter->endElement(); //segments
                $XMLWriter->endElement(); //file
            }
        }
        $XMLWriter->writeComment($this->_siteCommentString);
        $XMLWriter->endElement(); //nzb
        $XMLWriter->endDocument();
        $path = ($this->buildNZBPath($release->guid, $this->nzbSplitLevel, true).$release->guid.'.nzb.gz');
        $fp = gzopen($path, 'wb7');
        if (! $fp) {
            return false;
        }
        gzwrite($fp, $XMLWriter->outputMemory());
        gzclose($fp);
        unset($XMLWriter);
        if (! File::isFile($path)) {
            echo "ERROR: $path does not exist.\n";

            return false;
        }
        // Mark release as having NZB.
        DB::transaction(function () use ($release, $nzb_guid) {
            $release->update(['nzbstatus' => self::NZB_ADDED]);
            if (! empty($nzb_guid)) {
                $release->update(['nzb_guid' => DB::raw('UNHEX( '.escapeString(md5($nzb_guid)).' )')]);
            }
        }, 3);

        // Delete CBP for release that has its NZB created.
        DB::transaction(function () use ($release) {
            Collection::query()->where('collections.releases_id', $release->id)->delete();
        }, 3);
        // Chmod to fix issues some users have with file permissions.
        chmod($path, 0777);

        return true;
    }

    /**
     * Build a folder path on the hard drive where the NZB file will be stored.
     *
     * @param  string  $releaseGuid  The guid of the release.
     * @param  int  $levelsToSplit  How many sub-paths the folder will be in.
     * @param  bool  $createIfNotExist  Create the folder if it doesn't exist.
     * @return string $nzbpath The path to store the NZB file.
     */
    public function buildNZBPath($releaseGuid, $levelsToSplit, $createIfNotExist): string
    {
        $nzbPath = '';

        for ($i = 0; $i < $levelsToSplit && $i < 32; $i++) {
            $nzbPath .= $releaseGuid[$i].DS;
        }

        $nzbPath = $this->siteNzbPath.$nzbPath;

        if ($createIfNotExist && ! File::isDirectory($nzbPath) && ! File::makeDirectory($nzbPath, 0777, true) && ! File::isDirectory($nzbPath)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $nzbPath));
        }

        return $nzbPath;
    }

    /**
     * Retrieve path + filename of the NZB to be stored.
     *
     * @param  string  $releaseGuid  The guid of the release.
     * @param  int  $levelsToSplit  How many sub-paths the folder will be in. (optional)
     * @param  bool  $createIfNotExist  Create the folder if it doesn't exist. (optional)
     * @return string Path+filename.
     */
    public function getNZBPath($releaseGuid, $levelsToSplit = 0, $createIfNotExist = false): string
    {
        if ($levelsToSplit === 0) {
            $levelsToSplit = $this->nzbSplitLevel;
        }

        return $this->buildNZBPath($releaseGuid, $levelsToSplit, $createIfNotExist).$releaseGuid.'.nzb.gz';
    }

    /**
     * Determine is an NZB exists, returning the path+filename, if not return false.
     *
     * @param  string  $releaseGuid  The guid of the release.
     * @return false|string On success: (string) Path+file name of the nzb.
     *                      On failure: false .
     */
    public function NZBPath($releaseGuid)
    {
        $nzbFile = $this->getNZBPath($releaseGuid);

        return File::isFile($nzbFile) ? $nzbFile : false;
    }

    /**
     * Retrieve various information on a NZB file (the subject, # of pars,
     * file extensions, file sizes, file completion, group names, # of parts).
     *
     * @param  string  $nzb  The NZB contents in a string.
     * @param  array  $options
     *                          'no-file-key'    => True - use numeric array key; False - Use filename as array key.
     *                          'strip-count'    => True - Strip file/part count from file name to make the array key; False - Leave file name as is.
     * @return array $result Empty if not an NZB or the contents of the NZB.
     */
    public function nzbFileList($nzb, array $options = []): array
    {
        $defaults = [
            'no-file-key' => true,
            'strip-count' => false,
        ];
        $options += $defaults;

        $i = 0;
        $result = [];

        if (! $nzb) {
            return $result;
        }

        $xml = @simplexml_load_string(str_replace("\x0F", '', $nzb));
        if (! $xml || strtolower($xml->getName()) !== 'nzb') {
            return $result;
        }

        foreach ($xml->file as $file) {
            // Subject.
            $title = (string) $file->attributes()->subject;

            if ($options['no-file-key'] === false) {
                $i = $title;
                if ($options['strip-count']) {
                    // Strip file / part count to get proper sorting.
                    $i = preg_replace('#\d+[- ._]?(/|\||[o0]f)[- ._]?\d+?(?![- ._]\d)#i', '', $i);
                    // Change .rar and .par2 to be sorted before .part0x.rar and .volxxx+xxx.par2
                    if (str_contains($i, '.par2') && ! preg_match('#\.vol\d+\+\d+\.par2#i', $i)) {
                        $i = str_replace('.par2', '.vol0.par2', $i);
                    } elseif (preg_match('#\.rar[^a-z0-9]#i', $i) && ! preg_match('#\.part\d+\.rar$#i', $i)) {
                        $i = preg_replace('#\.rar(?:[^a-z0-9])#i', '.part0.rar', $i);
                    }
                }
            }

            $result[$i]['title'] = $title;

            // Extensions.
            if (preg_match(
                '/\.(\d{2,3}|7z|ace|ai7|srr|srt|sub|aiff|asc|avi|audio|bin|bz2|'
                .'c|cfc|cfm|chm|class|conf|cpp|cs|css|csv|cue|deb|divx|doc|dot|'
                .'eml|enc|exe|file|gif|gz|hlp|htm|html|image|iso|jar|java|jpeg|'
                .'jpg|js|lua|m|m3u|mkv|mm|mov|mp3|mp4|mpg|nfo|nzb|odc|odf|odg|odi|odp|'
                .'ods|odt|ogg|par2|parity|pdf|pgp|php|pl|png|ppt|ps|py|r\d{2,3}|'
                .'ram|rar|rb|rm|rpm|rtf|sfv|sig|sql|srs|swf|sxc|sxd|sxi|sxw|tar|'
                .'tex|tgz|txt|vcf|video|vsd|wav|wma|wmv|xls|xml|xpi|xvid|zip7|zip)'
                .'[" ](?!([\)|\-]))/i',
                $title,
                $ext
            )
            ) {
                if (preg_match('/\.r\d{2,3}/i', $ext[0])) {
                    $ext[1] = 'rar';
                }
                $result[$i]['ext'] = strtolower($ext[1]);
            } else {
                $result[$i]['ext'] = '';
            }

            $fileSize = $numSegments = 0;

            // Parts.
            if (! isset($result[$i]['segments'])) {
                $result[$i]['segments'] = [];
            }

            // File size.
            foreach ($file->segments->segment as $segment) {
                $result[$i]['segments'][] = (string) $segment;
                $fileSize += $segment->attributes()->bytes;
                $numSegments++;
            }
            $result[$i]['size'] = $fileSize;

            // File completion.
            if (preg_match('/(\d+)\)$/', $title, $parts)) {
                $result[$i]['partstotal'] = $parts[1];
            }
            $result[$i]['partsactual'] = $numSegments;

            // Groups.
            if (! isset($result[$i]['groups'])) {
                $result[$i]['groups'] = [];
            }
            foreach ($file->groups->group as $g) {
                $result[$i]['groups'][] = (string) $g;
            }

            unset($result[$i]['segments']['@attributes']);
            if ($options['no-file-key']) {
                $i++;
            }
        }

        return $result;
    }
}
