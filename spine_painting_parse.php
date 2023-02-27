<?php
$code = $argv[1] ?? '';
if(!$code) {
    die('empty code');
}

$filename = $argv[2] ?? '';
if(!$filename) {
    die('empty filepath');
}

$savepath = $argv[3] ?? '';
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
    if($lastID == '') {
        continue;
    }
    $object = &$objects[$lastID];
    if(substr($line, 0, 6) == 'm_Name') {
        $object['m_Name'] = trim(explode(' ', $line)[1], '"');
        continue;
    }
    if($line == 'm_GameObject  (PPtr<GameObject>)') {
        $index += 2;
        $parts = explode(' ', $lines[$index]);
        $object['m_GameObject'] = $parts[1];
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
    if($line == 'm_Father  (PPtr<Transform>)') {
        $index += 2;
        $parts = explode(' ', $lines[$index]);
        $object['m_Father'] = $parts[1];
        continue;
    }
    if($line == 'm_Children  (vector)') {
        $object['m_Children'] = [];
        continue;
    }
    if($line == 'data  (PPtr<Transform>)') {
        $index += 2;
        $parts = explode(' ', $lines[$index]);
        $object['m_Children'][] = $parts[1];
        continue;
    }
    
}

file_put_contents('kalangshitade.objects.json', json_encode($objects, JSON_PRETTY_PRINT));

$rects = [];

foreach($objects as $obj) {
    if(strtoupper($obj['m_Name'] ?? '') == strtoupper($code)) {
        $components = $obj['m_Component'];
        foreach($components as $componentPathID) {
            $component = $objects[$componentPathID] ?? null;
            if(is_null($component)) {
                continue;
            }
            if($component['type'] != 'RectTransform') {
                continue;
            }
            $children = $component['m_Children'] ?? [];
            if(count($children)) {
                foreach($children as $childPathID) {
                    $child = $objects[$childPathID] ?? null;
                    if(is_null($child)) continue;
                    if($child['type'] != 'RectTransform') continue;
                    $gameObject = $objects[$child['m_GameObject']];
                    $rects[$gameObject['m_Name']] = [
                        'size' => $child['m_SizeDelta'],
                        'pivot' => $child['m_Pivot'],
                        'position' => $child['m_AnchoredPosition'],
                    ];
                }    
            } else {
                $gameObject = $objects[$component['m_GameObject'] ?? ''] ?? null;
                if(is_null($gameObject)) {
                    continue;
                }
                $rects[$gameObject['m_Name']] = [
                    'size' => $component['m_SizeDelta'],
                    'pivot' => $component['m_Pivot'],
                    'position' => $component['m_AnchoredPosition'],
                ];
            }
        }
        break;
    }
}

if(!is_dir(dirname($savepath))) mkdir(dirname($savepath), 0777, true);

unset($rects['hitArea']);

file_put_contents($savepath, json_encode($rects, JSON_PRETTY_PRINT));