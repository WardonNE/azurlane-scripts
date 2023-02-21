<?php
$filename = $argv[1] ?? '';
if(!$filename) {
    die('empty filepath');
}

$savepath = $argv[2] ?? '';
if(!$savepath) {
    die('empty savepath');
}

$content = file_get_contents($filename);

$lines = explode("\r\n", $content);

$objects = [];

$lastID = '';

for($index = 0; $index < count($lines); $index++) {
    $line = trim($lines[$index]);
    if(substr($line, 0, 2) == 'ID') {
        list($_, $ID, $__, $___, $type) = explode(' ', $line);
        $objects[$ID] = [
            'id' => $ID,
            'type' => $type,
        ];
        $lastID = $ID;
        continue;
    }
    $object = &$objects[$lastID];
    if(substr($line, 0, 6) == 'm_Name') {
        $object['m_Name'] = trim(explode(' ', $line)[1], '"');
        continue;
    }
    if(substr($line, 0, 11) == 'm_Component') {
        $object['m_Component'] = [];
        continue;
    }
    if($line == 'data  (ComponentPair)') {
        $index += 3;
        $componentPathID = $lines[$index];
        $object['m_Component'][] = explode(' ', $componentPathID)[1];
        continue;
    } 
    if(substr($line, 0, 11) == 'm_AnchorMin') {
        $parts = explode(' ', $line);
        $object['m_AnchorMin'] = [
            ltrim($parts[1], '('),
            rtrim($parts[2], ')'),
        ];
        continue;
    }
    if(substr($line, 0, 18) == 'm_AnchoredPosition') {
        $parts = explode(' ', $line);
        $object['m_AnchoredPosition'] = [
            ltrim($parts[1], '('),
            rtrim($parts[2], ')'),
        ];
        continue;
    }
    if(substr($line, 0, 11) == 'm_SizeDelta') {
        $parts = explode(' ', $line);
        $object['m_SizeDelta'] = [
            ltrim($parts[1], '('),
            rtrim($parts[2], ')'),
        ];
        continue;
    }
    if(substr($line, 0, 7) == 'm_Pivot') {
        $parts = explode(' ', $line);
        $object['m_Pivot'] = [
            ltrim($parts[1], '('),
            rtrim($parts[2], ')'),
        ];
        continue;
    }
}

foreach($objects as $object) {
    if(($object['m_Name'] ?? '') == 'face') {
        $m_Components = $object['m_Component'] ?? [];
        foreach($m_Components as $m_Component) {
            $component = $objects[$m_Component] ?? [];
            if($component['type'] == 'RectTransform') {
                $rect = [
                    'anchor' => $component['m_AnchorMin'],
                    'position' => $component['m_AnchoredPosition'],
                    'size' => $component['m_SizeDelta'],
                    'pivot' => $component['m_Pivot'],
                ];
                if(!is_dir(dirname($savepath))) {
                    mkdir(dirname($savepath), 0777, true);
                }
                file_put_contents($savepath, json_encode($rect, JSON_PRETTY_PRINT));
            }
        }
        break;
    }
}
