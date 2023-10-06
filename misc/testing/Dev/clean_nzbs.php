<?php

require_once dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'bootstrap/autoload.php';

use App\Models\Release;
use App\Models\Settings;
use Blacklight\ColorCLI;
use Blacklight\NZB;
use Blacklight\ReleaseImage;
use Blacklight\Releases;
use Blacklight\utility\Utility;
use Illuminate\Support\Facades\File;

$dir = storage_path().'/nzb/movednzbs/';
$colorCli = new ColorCLI;

if (! isset($argv[1]) || ! in_array($argv[1], ['true', 'move'])) {
    $colorCli->error("This script can remove all nzbs not found in the db and all releases with no nzbs found. It can also move invalid nzbs.\n\n"
        ."php $argv[0] true       ...: For a dry run, to see how many would be moved.\n"
        ."php $argv[0] move       ...: Move NZBs that are possibly bad or have no release. They are moved into this folder: $dir.\n"
        ."php $argv[0] move #1 #2 ...: Same as previous, but skip first #1 nzbs, and #2 releases.");
    exit();
}

if (! File::isDirectory($dir) && ! File::makeDirectory($dir)) {
    exit("ERROR: Could not create folder [$dir].".PHP_EOL);
}

$releases = new Releases;
$nzb = new NZB;
$releaseImage = new ReleaseImage;

$timestart = now()->toRfc2822String();
$checked = $moved = 0;
$couldbe = ($argv[1] === 'true') ? 'could be ' : '';

$colorCli->header('Getting List of nzbs to check against db.');
$colorCli->header("Checked / {$couldbe}moved\n");

$dirItr = new RecursiveDirectoryIterator(Settings::settingValue('..nzbpath'));
$itr = new RecursiveIteratorIterator($dirItr, RecursiveIteratorIterator::LEAVES_ONLY);

if (!isset($argv[2])) {
    $argv[2] = 0;
}

$checked = 0;
foreach ($itr as $filePath) {
    if ($checked++ < $argv[2]) {
        if ($checked % 1000 == 0) {
            echo ' '.number_format($checked).' / '.number_format($moved)."          \r";
        }
        continue;
    }

    $guid = stristr($filePath->getFilename(), '.nzb.gz', true);
    if (File::isFile($filePath) && $guid) {
        $nzbfile = Utility::unzipGzipFile($filePath);
        $nzbContents = $nzb->nzbFileList($nzbfile, ['no-file-key' => false, 'strip-count' => true]);
        if (! $nzbfile || ! @simplexml_load_string($nzbfile) || count($nzbContents) === 0) {
            if ($argv[1] === 'move') {
                rename($filePath, $dir.$guid.'.nzb.gz');
            }
            $releases->deleteSingle(['g' => $guid, 'i' => false], $nzb, $releaseImage);
            $moved++;
        }
        $checked++;
        if ($checked % 100 == 0) {
            echo ' '.number_format($checked).' / '.number_format($moved)."          \r";
        }
        echo "\r";
    }
}

$colorCli->header("\n".number_format($checked).' nzbs checked, '.number_format($moved).' nzbs '.$couldbe.'moved.');
$colorCli->header('Getting List of releases to check against nzbs.');
$colorCli->header("Checked / releases deleted\n");

$checked = $deleted = 0;

if (!isset($argv[3])) {
    $argv[3] = 0;
}

$res = Release::query()->select(['id', 'guid', 'nzbstatus'])->get();
foreach ($res as $row) {
    if ($checked++ < $argv[3]) {
        if ($checked % 1000 == 0) {
            echo ' '.number_format($checked).' / '.number_format($deleted)."          \r";
        }
        continue;
    }

    $nzbpath = $nzb->getNZBPath($row->guid);
    if (! File::isFile($nzbpath)) {
        $deleted++;
        $releases->deleteSingle(['g' => $row->guid, 'i' => $row->id], $nzb, $releaseImage);
    } elseif ($row->nzbstatus !== 1) {
        Release::where('id', $row->id)->update(['nzbstatus' => 1]);
    }
    $checked++;
    if ($checked % 100 == 0) {
        echo ' '.number_format($checked).' / '.number_format($deleted)."          \r";
    }
    echo "\r";
}
$colorCli->header("\n".number_format($checked).' releases checked, '.number_format($deleted).' releases deleted.');
$colorCli->header("Script started at [$timestart], finished at [".now()->toRfc2822String().']');
