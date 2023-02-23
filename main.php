<?php
declare(strict_types=1);

define('DS', DIRECTORY_SEPARATOR);
define('LIVE2D_EXTRACTOR', 'bin\UnityLive2DExtractor.v1.0.7\UnityLive2DExtractor.exe');
define('BINARY_2_TEXT', 'bin\binary2text.exe');

function jsonEncode($data) : string
{   
    return json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
}

class AzurlaneScript 
{
    private $config = [];

    private $extractedLive2D = [];

    private $restoredPaintings = [];

    private $ships = [];

    private $skins = [];

    private $archivedFace = [];

    private $noFace = [];

    private $parsedFaceRect = [];

    public function __construct()
    {
        $this->loadConfig();
        $this->loadExtractedLive2D();
        $this->loadRestoredPaintings();
        $this->loadArchivedFace();
        $this->loadParsedFaceRect();
        $this->loadShips();
        $this->loadSkins();
    }

    public function __destruct()
    {
        file_put_contents($this->config['extracted_live2d_json'], jsonEncode($this->extractedLive2D));
        file_put_contents($this->config['restored_paintings_json'], jsonEncode($this->restoredPaintings));
        file_put_contents($this->config['ship_list_json'], jsonEncode($this->ships));
        file_put_contents($this->config['skin_list_json'], jsonEncode($this->skins));
        file_put_contents($this->config['archived_face_json'], jsonEncode($this->archivedFace));
        file_put_contents('no_face.json', jsonEncode($this->noFace));
        file_put_contents($this->config['parsed_face_rect_json'], jsonEncode($this->parsedFaceRect));
    }


    public function loadConfig()
    {
        $this->config = json_decode(file_get_contents('config.json'), true);
    }

    public function loadExtractedLive2D()
    {
        if (is_file($this->config['extracted_live2d_json'])) {
            $this->extractedLive2D = json_decode(file_get_contents($this->config['extracted_live2d_json']), true) ?? [];
        }
    }

    public function loadRestoredPaintings()
    {
        if(is_file($this->config['restored_paintings_json'])) {
            $this->restoredPaintings = json_decode(file_get_contents($this->config['restored_paintings_json']), true) ?? [];
        }
    }

    public function loadShips()
    {
        if(is_file($this->config['ship_list_json'])) {
            $this->ships = json_decode(file_get_contents($this->config['ship_list_json']), true);
        } else {
            $this->ships = json_decode(file_get_contents($this->config['ship_list_api']), true);
        }
    }

    public function loadSkins()
    {
        if(is_file($this->config['skin_list_json'])) {
            $this->skins = json_decode(file_get_contents($this->config['skin_list_json']), true) ?? [];
        } else {
            $this->skins = json_decode(file_get_contents($this->config['skin_list_api']), true) ?? [];
        }
    }

    public function loadArchivedFace()
    {
        if(is_file($this->config['archived_face_json'])) {
            $this->archivedFace = json_decode(file_get_contents($this->config['archived_face_json']), true) ?? [];
        }
    }

    public function loadParsedFaceRect()
    {
        if(is_file($this->config['parsed_face_rect_json'])) {
            $this->parsedFaceRect = json_decode(file_get_contents($this->config['parsed_face_rect_json']), true) ?? [];
        }
    }

    private function output(string $message, bool $eof = true)
    {
        echo $message, $eof ? PHP_EOL : '';
    }

    private function execute(string $command, string $send = '') 
    {
        $p = popen($command, 'w');
        while(!feof($p)) {
            $line = fgets($p);
            if($line == false) continue;
            if(trim($line) == '') continue;
            $this->output($line, false);
        }
        if($send != '') {
            fwrite($p, $send);
        }
        pclose($p);
    }

    public function restorePaintings() 
    {
        foreach($this->skins as $skins) {
            foreach($skins as $code => $skin) {
                if(in_array($code, $this->restoredPaintings)) continue;
                $this->downloadRect($code);
                $command = implode(' ', ['azurlane-scripts.exe', "--code=$code"]);
                $this->execute($command);
            }
        }
    }

    public function downloadRect(string $code)
    {   
        $localRect = sprintf($this->config['local_rect_path'], $code);
        if(!is_file($localRect)) {
            $data = json_decode(file_get_contents($localRect), true);
            if(is_null($data)) {
                throw new Exception('download rect failed: ' . $code);
            }
            file_put_contents($localRect, jsonEncode($data));
        }
    }

    public function extractLive2D()
    {
        $live2dPath = implode(DS, [__DIR__, 'source', 'live2d']);
        $dirEntries = scandir($live2dPath);
        foreach($dirEntries as $dirEntry) {
            if($dirEntry === '.' || $dirEntry === '..') continue;
            if(in_array($dirEntry, $this->extractedLive2D)) continue;
            $entryPath = $live2dPath . DS . $dirEntry;
            $inputPath = implode(DS, [__DIR__, 'input', 'live2d', $dirEntry, $dirEntry]);
            $live2dResourcePath = sprintf($this->config['live2d_output_path'], $dirEntry);
            if($dirEntry == 'junhe_5' || $dirEntry == 'xukufu_2') {
            } else {
                if(!is_file($inputPath)) {
                    if(!is_dir(dirname($inputPath))) {
                        mkdir($inputPath, 0777, true);
                    }
                    copy($entryPath, $inputPath . DS . $dirEntry);
                }
    
                $command = implode(' ', [LIVE2D_EXTRACTOR, dirname($inputPath)]);
                $this->execute($command);
                if(is_dir($live2dResourcePath)) {
                    $this->rmdir($live2dResourcePath);
                }
                if(!is_dir(dirname($live2dResourcePath))) {
                    mkdir(dirname($live2dResourcePath), 0777, true);
                }
                rename(implode(DS, [__DIR__, 'input', 'live2d', 'Live2DOutput', 'assets', 'artresource', 'live2d', $dirEntry]), $live2dResourcePath);
            }
            
            $modelFile = $live2dResourcePath . DS . $dirEntry . '.model3.json';
            if(!is_file($modelFile)) {
                throw new Exception('model not exists: '.$modelFile);
            }
            $model = json_decode(file_get_contents($modelFile), true);
            $motions = $model['FileReferences']['Motions'][''];
            if(!is_null($motions)) {
                foreach($motions as $motion) {
                    $file = $motion['File'];
                    $motionName = str_replace(' ', '', ucwords(str_replace('_', ' ', explode('.', explode('/', $file)[1])[0])));
                    $model['FileReferences']['Motions'][$motionName] = [
                        ['File' => $file],
                    ];
                }
                unset($model['FileReferences']['Motions']['']);
                file_put_contents($modelFile, json_encode($model, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
            }
            $this->extractedLive2D[] = $dirEntry;
        }
        if(is_dir(implode(DS, [__DIR__, 'input', 'live2d', 'Live2DOutput']))) {
            $this->rmdir(implode(DS, [__DIR__, 'input', 'live2d', 'Live2DOutput']));
        }
    }

    public function archivePaintingFaces()
    {
        foreach($this->skins as $skins) {
            foreach($skins as $code => $_) {
                if(in_array($code, $this->archivedFace)) continue;
                $faceInput = sprintf($this->config['painting_face_input_path'], $code);
                if(!is_dir($faceInput)) {
                    $this->noFace[] = $code;
                    continue;
                }
                $faceOutput = sprintf($this->config['painting_face_output_path'], $code);
                if(is_dir($faceOutput)) {
                    $this->rmdir($faceOutput);
                }
                if(!is_dir(dirname($faceOutput))) {
                    mkdir(dirname($faceOutput), 0777, true);
                }
                $this->copyDir($faceInput, $faceOutput);
                $this->archivedFace[] = $code;
            }
        }
    }

    public function parseFaceRect()
    {
        foreach($this->archivedFace as $code) {
            $unpackedInput = sprintf($this->config['unpacked_input'], $code);
            if(!is_dir($unpackedInput)) {
                $faceDir = sprintf($this->config['painting_face_output_path'], $code);
                if(is_dir($faceDir)) {
                    $this->rmdir($faceDir);
                }
                continue;
            }
            if(in_array($code, $this->parsedFaceRect)) continue;
            $this->output('parsing face rect: ' . $code);
            $items = scandir($unpackedInput);
            foreach($items as $item) {
                if($item == '.' || $item == '..') {
                    continue;
                }
                if(pathinfo($item, PATHINFO_EXTENSION) == '') {
                    $command = implode(' ', [BINARY_2_TEXT, $unpackedInput . DS . $item]);
                    $this->execute($command);
                    
                    $txtPath = $unpackedInput . DS . $item . '.txt';
                    $savePath = sprintf($this->config['local_face_rect_path'], $code);
                    $command = implode(' ', ['php', 'parse.php', $txtPath, $savePath]);
                    $this->execute($command);
                    unlink($txtPath);
                    break;
                }
            }
            $this->parsedFaceRect[] = $code;
            $this->output('parsed face rect: ' . $code);
        }
    }

    public function archiveSpine()
    {
        $dirEntries = scandir($this->config['spine_input']);
        foreach($dirEntries as $dirEntry) {
            if($dirEntry == '.' || $dirEntry == '..') continue;
            $files = scandir($this->config['spine_input'] . DS . $dirEntry);
            foreach($files as $file) {
                if($file == '.' || $file == '..') continue;
                if(pathinfo($file, PATHINFO_EXTENSION) == 'asset') {
                    $filename = $this->config['spine_input'] . DS . $dirEntry . DS . $file;
                    $newfilename = $this->config['spine_input'] . DS . $dirEntry . DS . str_replace('.asset', '', $file);
                    rename($filename, $newfilename);
                }
            }
            if(!is_dir($this->config['spine_output'])) {
                mkdir($this->config['spine_output'], 0777, true);
            }
            $this->copyDir($this->config['spine_input'] . DS . $dirEntry, $this->config['spine_output'] . DS . $dirEntry);
        }
    }

    public function generateData()
    {
        $data = [];
        foreach($this->ships as $shipCode => $shipInfo) {
            $data[$shipCode] = [
                'code' => strval($shipCode),
                'name' => $shipInfo['name'],
                'avatar' => $shipCode.'.png',
                'type' => $shipInfo['type'],
                'rarity' => $shipInfo['rarity'],
                'nationality' => $shipInfo['nationality'],
                'skins' => [],
            ];
            $this->downloadAvatar(strval($shipCode));
            $skins = $this->skins[$shipCode] ?? [];
            foreach($skins as $skinCode => $skinInfo) {
                $skin = [
                    'code' => strval($skinCode),
                    'name' => $skinInfo['name'],
                    'avatar' => $skinCode.'.png',
                    'painting' => $skinCode . '/' . $skinCode . '.png',
                ];
                $faceDirpath = sprintf($this->config['painting_face_output_path'], $skinCode);
                if(is_dir($faceDirpath)) {
                    $faces = scandir($faceDirpath) ?: [];
                    $faces = array_values(array_filter($faces, function($face) {
                        return $face != '.' && $face != '..';
                    }));
                    $skin['faces'] = array_map(function($face) use ($skinCode) {
                        return $skinCode . '/' . $face;
                    }, $faces);
                    $skin['face_rect'] = $skinCode . '.json';
                }
                $live2dPath = sprintf($this->config['live2d_output_path'], $skinCode);
                if(is_dir($live2dPath)) {
                    $skin['live2d'] = $skinCode . '/' . $skinCode . '.model3.json';
                }
                $spinePath = $this->config['spine_output'] . DS . $skinCode;
                if(is_dir($spinePath)) {
                    $skin['spine'] = [
                        'skel' => $skinCode . '/' . $skinCode . '.skel',
                        'atlas' => $skinCode . '/' . $skinCode . '.atlas',
                    ];
                }
                $data[$shipCode]['skins'][] = $skin;
                $this->downloadAvatar(strval($skinCode));
            }
        }
        file_put_contents($this->config['data_json'], jsonEncode(array_values($data)));
    }

    public function downloadAvatar(string $code) 
    {
        $savePath = sprintf($this->config['avatar_output'], $code);
        if(is_file($savePath)) return;
        if(!is_dir(dirname($savePath))) {
            mkdir(dirname($savePath), 0777, true);
        }
        $downloadUrl = sprintf($this->config['avatar_download_url'], $code);
        $this->output('downloading: ' . $downloadUrl);
        file_put_contents($savePath, file_get_contents($downloadUrl));
    }

    private function rmdir(string $dirpath) 
    {
        $rmdirCommand = implode(' ', ['rmdir', str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dirpath), '/S', '/Q', ]);
        $this->execute($rmdirCommand);
    }

    private function copyDir(string $from, string $to) : void
    {
        $copyCommand = implode(' ', [
            'xcopy', 
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR ,$from), 
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $to), 
            '/Y', 
            '/E', 
            '/I', 
            '/Q',
            '/D',
        ]);
        $this->execute($copyCommand);
    }
}

function main() 
{
    ini_set('memory_limit', '1024M');
    try {
        $script = new AzurlaneScript();
        sapi_windows_set_ctrl_handler(function($event) {
            if($event === PHP_WINDOWS_EVENT_CTRL_C) {
                throw new Exception('Cancelled');
            }
        });
        $script->extractLive2D();
        $script->restorePaintings();
        $script->archivePaintingFaces();
        $script->parseFaceRect();
        $script->archiveSpine();
        $script->generateData();
    } catch(Throwable $e) {
        echo $e->getMessage(), PHP_EOL;
        echo $e->getTraceAsString(), PHP_EOL;
    }
}

main();