<?php
error_reporting(0);
set_time_limit(0);

 $favFile = __DIR__ . '/favorites.json';
 $favorites = [];
if (file_exists($favFile)) {
    $favorites = json_decode(file_get_contents($favFile), true) ?: [];
}

 $scanResultFile = __DIR__ . '/scan_result.txt';
 $scanDoneFile = __DIR__ . '/scan_done.flag';
 $psScriptFile = __DIR__ . '/scan_task.ps1';
 $psIconScript = __DIR__ . '/icon_task.ps1';
 $psDetailsScript = __DIR__ . '/details_task.ps1';

if (isset($_GET['action'])) {
    $path = base64_decode($_GET['p']);
    
    if ($_GET['action'] == 'icon') {
        $cacheDir = __DIR__ . '/cache';
        if (!is_dir($cacheDir)) mkdir($cacheDir);
        $cacheFile = $cacheDir . '/' . md5($path) . '.png';
        
        if (!file_exists($cacheFile)) {
            $safePath = str_replace("'", "''", $path);
            $safeCache = str_replace("'", "''", $cacheFile);
            $scriptContent = "Add-Type -AssemblyName System.Drawing\n[System.Drawing.Icon]::ExtractAssociatedIcon('$safePath').ToBitmap().Save('$safeCache')";
            file_put_contents($psIconScript, $scriptContent);
            shell_exec("powershell -ExecutionPolicy Bypass -NoProfile -File \"" . $psIconScript . "\" 2>&1");
        }
        
        if (file_exists($cacheFile)) {
            header('Content-Type: image/png');
            readfile($cacheFile);
        } else {
            header('HTTP/1.1 404 Not Found');
        }
        exit;
    }
    
    if ($_GET['action'] == 'run') {
        pclose(popen('cmd /c start "" "' . $path . '"', 'r'));
        echo json_encode(['status' => 'ok']);
        exit;
    }
    
    if ($_GET['action'] == 'folder') {
        pclose(popen('explorer.exe /select,"' . $path . '"', 'r'));
        echo json_encode(['status' => 'ok']);
        exit;
    }

    if ($_GET['action'] == 'get_details') {
        $safePath = str_replace("'", "''", $path);
        $scriptContent = "
\$f = Get-Item -LiteralPath '$safePath'
\$info = \$f.VersionInfo
[PSCustomObject]@{
    Name = \$f.Name
    Path = \$f.FullName
    Dir = \$f.DirectoryName
    SizeMB = [math]::Round(\$f.Length / 1MB, 2)
    Created = \$f.CreationTime.ToString('yyyy-MM-dd HH:mm:ss')
    Modified = \$f.LastWriteTime.ToString('yyyy-MM-dd HH:mm:ss')
    Company = \$info.CompanyName
    Version = \$info.FileVersion
    Description = \$info.FileDescription
    Product = \$info.ProductName
} | ConvertTo-Json -Compress
";
        file_put_contents($psDetailsScript, $scriptContent);
        $output = shell_exec("powershell -ExecutionPolicy Bypass -NoProfile -File \"" . $psDetailsScript . "\" 2>&1");
        
        $json = json_decode($output, true);
        if ($json === null) {
            echo json_encode(['Name' => basename($path), 'Path' => $path, 'Error' => 'Cannot read details']);
        } else {
            echo $output;
        }
        exit;
    }

    if ($_GET['action'] == 'toggle_fav') {
        if (in_array($path, $favorites)) {
            $favorites = array_diff($favorites, [$path]);
        } else {
            array_unshift($favorites, $path);
        }
        file_put_contents($favFile, json_encode(array_values($favorites)));
        echo json_encode(['status' => 'ok', 'favorites' => array_values($favorites)]);
        exit;
    }

    if ($_GET['action'] == 'start_scan_bg') {
        @unlink($scanResultFile);
        @unlink($scanDoneFile);
        
        $drivesOutput = shell_exec('wmic logicaldisk get name');
        preg_match_all('/([A-Z]:)/', $drivesOutput, $matches);
        $paths = [];
        foreach ($matches[0] as $drive) {
            $paths[] = "'" . $drive . "\\'";
        }
        $pathsStr = implode(',', $paths);
        
        $psScriptContent = "\$ErrorActionPreference = 'SilentlyContinue'\nGet-ChildItem -Path $pathsStr -Filter *.exe -Recurse | Select-Object -ExpandProperty FullName | Out-File -FilePath '$scanResultFile' -Encoding UTF8\nNew-Item -Path '$scanDoneFile' -ItemType File -Force | Out-Null";
        file_put_contents($psScriptFile, $psScriptContent);
        
        pclose(popen("start /B powershell -ExecutionPolicy Bypass -NoProfile -File \"" . $psScriptFile . "\"", "r"));
        echo json_encode(['status' => 'started']);
        exit;
    }

    if ($_GET['action'] == 'check_scan') {
        $done = file_exists($scanDoneFile);
        $count = 0;
        if (file_exists($scanResultFile)) {
            $lines = file($scanResultFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $count = count($lines);
        }
        echo json_encode(['done' => $done, 'count' => $count]);
        exit;
    }

    if ($_GET['action'] == 'get_scan_data') {
        $filterRegex = '/(unins|update|helper|setup|crash|report|redist|dotnet|vc_redist|recycle|WinSxS|servicing|assembly|cache|node_modules|\.tmp|temp)/i';
        $data = [];
        if (file_exists($scanResultFile)) {
            $lines = file($scanResultFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $uniqueLines = array_unique($lines);
            foreach ($uniqueLines as $line) {
                if (!preg_match($filterRegex, $line)) {
                    $data[] = [
                        'path' => $line,
                        'b64' => base64_encode($line),
                        'name' => basename($line),
                        'dir' => dirname($line)
                    ];
                }
            }
        }
        echo json_encode(['data' => array_values($data)]);
        exit;
    }
}

function scanExe($paths, $filter = false) {
    $result = [];
    $filterRegex = '/(unins|update|helper|setup|crash|report|redist|dotnet|vc_redist|recycle|WinSxS|servicing|assembly|cache|node_modules|\.tmp|temp)/i';
    
    foreach ($paths as $path) {
        if (empty($path)) continue;
        $command = 'powershell -Command "Get-ChildItem -Path \'' . $path . '\\\' -Filter *.exe -Recurse -ErrorAction SilentlyContinue | Select-Object -ExpandProperty FullName"';
        $output = shell_exec($command);
        $lines = explode("\n", trim($output));
        foreach ($lines as $line) {
            $cleanLine = trim($line);
            if (empty($cleanLine)) continue;
            if ($filter && preg_match($filterRegex, $cleanLine)) {
                continue;
            }
            $result[] = $cleanLine;
        }
    }
    return $result;
}

 $installedPaths = ['C:\Program Files', 'C:\Program Files (x86)'];
 $systemPaths = ['C:\Windows\System32'];

 $installedExes = scanExe($installedPaths, true);
 $systemExes = scanExe($systemPaths, true);

 $gameRegex = '/(steam|steamapps|epic games|epicgames|gog|galaxy|origin|ea desktop|ea games|riot client|riot games|blizzard|battle.net|ubisoft|ubisoft game launcher|xboxapp|windowsapps|games)/i';

 $gameExes = [];
 $appExes = [];

foreach ($installedExes as $exe) {
    if (preg_match($gameRegex, $exe)) {
        $gameExes[] = $exe;
    } else {
        $appExes[] = $exe;
    }
}

 $favExes = $favorites;

 $sortFunc = function($a, $b) use ($favorites) {
    $aFav = in_array($a, $favorites);
    $bFav = in_array($b, $favorites);
    if ($aFav && !$bFav) return -1;
    if (!$aFav && $bFav) return 1;
    return 0;
};

usort($gameExes, $sortFunc);
usort($appExes, $sortFunc);
usort($systemExes, $sortFunc);

 $hasCachedScan = file_exists($scanDoneFile);

 $tabs = [
    'fav' => ['name' => 'ถูกใจ', 'icon' => '❤', 'data' => $favExes],
    'games' => ['name' => 'เกมส์', 'icon' => '🎮', 'data' => $gameExes],
    'apps' => ['name' => 'แอปพลิเคชัน', 'icon' => '💻', 'data' => $appExes],
    'system' => ['name' => 'ไฟล์ระบบ', 'icon' => '⚙️', 'data' => $systemExes],
    'all' => ['name' => 'คลังทั้งหมด', 'icon' => '📁', 'data' => []],
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Launcher</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
body { font-family: 'Segoe UI', sans-serif; background-color: #0a0e17; color: #e2e8f0; overflow: hidden; }
.sidebar-bg { background: linear-gradient(180deg, #111827 0%, #0a0e17 100%); border-right: 1px solid #1e293b; }
.card-bg { background: linear-gradient(145deg, #1e293b 0%, #172033 100%); }
.card-hover { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid rgba(255,255,255,0.05); }
.card-hover:hover { transform: translateY(-5px); border-color: #6366f1; box-shadow: 0 10px 30px -10px rgba(99, 102, 241, 0.4); }
.fav-active { color: #f472b6 !important; }
.spinner { border: 4px solid rgba(99, 102, 241, 0.2); width: 60px; height: 60px; border-radius: 50%; border-top-color: #818cf8; animation: spin 1s linear infinite; }
@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
.modal-bg { backdrop-filter: blur(8px); background: rgba(0, 0, 0, 0.8); }
.nav-item { transition: all 0.2s ease; border-left: 3px solid transparent; }
.nav-item:hover { background: rgba(255,255,255,0.05); }
.nav-item.active { background: rgba(99, 102, 241, 0.15); border-left-color: #818cf8; color: #fff; }
.modal-enter { animation: modalIn 0.3s ease forwards; }
@keyframes modalIn { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
</style>
</head>
<body>

<div class="flex h-screen">
    <aside class="w-64 sidebar-bg flex flex-col flex-shrink-0">
        <div class="p-6 border-b border-slate-800">
            <h1 class="text-2xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-indigo-400 to-purple-500">⚡ LAUNCHER</h1>
        </div>
        <nav class="flex-1 overflow-y-auto py-4">
            <?php foreach ($tabs as $key => $tab): ?>
                <button onclick="switchTab('<?php echo $key; ?>')" id="tab-<?php echo $key; ?>" class="nav-item w-full text-left px-6 py-3 flex items-center justify-between <?php echo $key === 'fav' ? 'active' : 'text-slate-400'; ?>">
                    <span class="flex items-center gap-3"><span class="text-lg"><?php echo $tab['icon']; ?></span> <?php echo $tab['name']; ?></span>
                    <span id="count-<?php echo $key; ?>" class="text-xs bg-slate-700 px-2 py-1 rounded-full"><?php echo count($tab['data']); ?></span>
                </button>
            <?php endforeach; ?>
        </nav>
    </aside>

    <main class="flex-1 flex flex-col overflow-hidden">
        <header class="px-8 py-4 border-b border-slate-800 flex justify-between items-center bg-slate-900/50 flex-shrink-0">
            <h2 id="header-title" class="text-xl font-semibold text-white">ถูกใจ</h2>
            <div class="flex items-center gap-4">
                <div class="relative flex">
                    <input type="text" id="searchInput" placeholder="พิมพ์คำค้นหาแล้วกด Enter..." class="bg-slate-800 border border-slate-700 text-slate-200 rounded-l-lg py-2 px-4 w-64 focus:outline-none focus:border-indigo-500 text-sm">
                    <button onclick="doSearch()" class="bg-indigo-600 hover:bg-indigo-500 text-white px-4 rounded-r-lg text-sm font-medium transition-colors">ค้นหา</button>
                </div>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-8">
            <?php foreach ($tabs as $key => $tab): ?>
            <div id="content-<?php echo $key; ?>" class="tab-content grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6 <?php echo $key !== 'fav' ? 'hidden' : ''; ?>">
                <?php if ($key === 'all'): ?>
                    <div id="all-scan-container" class="col-span-full flex flex-col items-center justify-center py-32">
                        <?php if ($hasCachedScan): ?>
                            <div class="spinner mb-6"></div>
                            <p class="text-slate-400 mb-2">กำลังโหลดข้อมูลแคช...</p>
                        <?php else: ?>
                            <p class="text-slate-400 mb-6 text-lg">ยังไม่มีข้อมูลไฟล์ในเครื่อง</p>
                            <button onclick="startScan()" class="bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white font-bold py-3 px-8 rounded-lg transition-all text-lg shadow-lg shadow-indigo-500/30">เริ่มสแกนไฟล์ทั้งหมด</button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php if (empty($tab['data'])): ?>
                        <div class="col-span-full text-center py-32 text-slate-600">ไม่พบข้อมูลในส่วนนี้</div>
                    <?php else: ?>
                        <?php foreach ($tab['data'] as $exe): 
                            $b64 = base64_encode($exe);
                            $name = basename($exe);
                            $dir = dirname($exe);
                            $isFav = in_array($exe, $favorites);
                        ?>
                        <div class="app-card card-hover card-bg rounded-xl overflow-hidden flex flex-col relative">
                            <button onclick="event.stopPropagation(); toggleLike('<?php echo $b64; ?>', this)" class="absolute top-3 right-3 text-slate-500 hover:text-pink-400 z-10 <?php echo $isFav ? 'fav-active' : ''; ?>">
                                <svg class="w-5 h-5" fill="<?php echo $isFav ? 'currentColor' : 'none'; ?>" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path></svg>
                            </button>
                            <div class="p-5 flex items-center justify-center h-32 bg-slate-800/30 border-b border-slate-700/50">
                                <img src="?action=icon&p=<?php echo urlencode($b64); ?>" class="w-16 h-16 object-contain drop-shadow-2xl" alt="Icon" loading="lazy">
                            </div>
                            <div class="p-4 flex-grow flex flex-col">
                                <h3 class="font-bold text-white text-sm mb-1 truncate" title="<?php echo htmlspecialchars($name); ?>"><?php echo htmlspecialchars($name); ?></h3>
                                <p class="text-xs text-slate-500 mb-4 truncate flex-grow" title="<?php echo htmlspecialchars($dir); ?>"><?php echo htmlspecialchars($dir); ?></p>
                                <div class="grid grid-cols-3 gap-1 mt-auto">
                                    <button onclick="runApp('<?php echo $b64; ?>')" class="col-span-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold py-2 rounded transition-colors flex items-center justify-center gap-1">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"></path></svg>
                                        PLAY
                                    </button>
                                    <button onclick="getDetails('<?php echo $b64; ?>')" class="bg-slate-700 hover:bg-slate-600 text-white text-xs font-bold py-2 rounded transition-colors flex items-center justify-center" title="รายละเอียด">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </main>
</div>

<div id="detailsModal" class="modal-bg hidden fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="card-bg rounded-2xl p-8 max-w-lg w-full border border-slate-700 shadow-2xl relative modal-enter">
        <button onclick="closeModal()" class="absolute top-4 right-4 text-slate-400 hover:text-white text-2xl">&times;</button>
        <div class="flex items-center gap-4 mb-6 border-b border-slate-700 pb-4">
            <img id="modal-icon" src="" class="w-16 h-16 object-contain drop-shadow-lg">
            <div class="overflow-hidden">
                <h2 id="modal-title" class="text-xl font-bold text-white truncate">กำลังโหลด...</h2>
                <p id="modal-company" class="text-sm text-indigo-400 truncate">-</p>
            </div>
        </div>
        <div class="space-y-3 text-sm">
            <div class="flex justify-between gap-4"><span class="text-slate-400 flex-shrink-0">ขนาดไฟล์:</span> <span id="modal-size" class="text-white font-medium text-right">-</span></div>
            <div class="flex justify-between gap-4"><span class="text-slate-400 flex-shrink-0">เวอร์ชั่น:</span> <span id="modal-version" class="text-white font-medium text-right">-</span></div>
            <div class="flex justify-between gap-4"><span class="text-slate-400 flex-shrink-0">คำอธิบาย:</span> <span id="modal-desc" class="text-white font-medium text-right">-</span></div>
            <div class="flex justify-between gap-4"><span class="text-slate-400 flex-shrink-0">วันที่สร้าง:</span> <span id="modal-created" class="text-white font-medium text-right">-</span></div>
            <div class="flex justify-between gap-4"><span class="text-slate-400 flex-shrink-0">วันที่แก้ไข:</span> <span id="modal-modified" class="text-white font-medium text-right">-</span></div>
            <div class="flex flex-col gap-1 pt-2 border-t border-slate-700 mt-4">
                <span class="text-slate-400">ตำแหน่ง:</span>
                <p id="modal-path" class="text-slate-300 bg-slate-900/50 p-2 rounded text-xs break-all">-</p>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-3 mt-6">
            <button onclick="openFolderFromModal()" class="bg-slate-700 hover:bg-slate-600 text-white font-bold py-2 rounded transition-colors text-sm">เปิดโฟลเดอร์</button>
            <button onclick="runFromModal()" class="bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-2 rounded transition-colors text-sm">เรียกใช้โปรแกรม</button>
        </div>
    </div>
</div>

<script>
let scanInterval = null;
let allExesData = [];
let currentModalB64 = null;
const favArrayJs = <?php echo json_encode($favorites); ?>;

document.getElementById('searchInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') doSearch();
});

function doSearch() {
    const query = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('.tab-content:not(.hidden)').forEach(container => {
        container.querySelectorAll('.app-card').forEach(card => {
            const text = card.innerText.toLowerCase();
            card.style.display = text.includes(query) ? 'flex' : 'none';
        });
    });
}

function switchTab(tabName) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
    document.getElementById('content-' + tabName).classList.remove('hidden');
    document.getElementById('tab-' + tabName).classList.add('active');
    
    const titles = { fav: 'ถูกใจ', games: 'เกมส์', apps: 'แอปพลิเคชัน', system: 'ไฟล์ระบบ', all: 'คลังทั้งหมด' };
    document.getElementById('header-title').innerText = titles[tabName];
    
    document.getElementById('searchInput').value = '';
    document.querySelectorAll('.app-card').forEach(card => card.style.display = 'flex');

    if (tabName === 'all' && allExesData.length === 0 && <?php echo $hasCachedScan ? 'true' : 'false'; ?>) {
        loadCachedScan();
    }
}

function runApp(b64) {
    fetch('?action=run&p=' + b64).then(r => r.json()).then(data => console.log('Running:', data));
}

function openFolder(b64) {
    fetch('?action=folder&p=' + b64).then(r => r.json()).then(data => console.log('Opening folder:', data));
}

function toggleLike(b64, el) {
    fetch('?action=toggle_fav&p=' + b64)
        .then(r => r.json())
        .then(data => {
            const card = el.closest('.app-card');
            const container = card.parentNode;
            const icon = el.querySelector('svg');
            
            if (icon.getAttribute('fill') === 'none') {
                icon.setAttribute('fill', 'currentColor');
                el.classList.add('fav-active');
                container.prepend(card);
            } else {
                icon.setAttribute('fill', 'none');
                el.classList.remove('fav-active');
                if (container.id === 'content-fav') {
                    card.remove();
                } else {
                    container.appendChild(card);
                }
            }
        });
}

function getDetails(b64) {
    currentModalB64 = b64;
    document.getElementById('modal-icon').src = '?action=icon&p=' + encodeURIComponent(b64);
    document.getElementById('modal-title').innerText = 'กำลังโหลด...';
    document.getElementById('modal-company').innerText = '-';
    document.getElementById('modal-size').innerText = '-';
    document.getElementById('modal-version').innerText = '-';
    document.getElementById('modal-desc').innerText = '-';
    document.getElementById('modal-created').innerText = '-';
    document.getElementById('modal-modified').innerText = '-';
    document.getElementById('modal-path').innerText = '-';
    
    const modal = document.getElementById('detailsModal');
    modal.classList.remove('hidden');
    modal.querySelector('div').classList.add('modal-enter');
    
    fetch('?action=get_details&p=' + b64)
        .then(r => r.json())
        .then(data => {
            if (data) {
                document.getElementById('modal-title').innerText = data.Name || 'ไม่ทราบชื่อ';
                document.getElementById('modal-company').innerText = data.Company || 'ไม่ระบุบริษัท';
                document.getElementById('modal-size').innerText = data.SizeMB !== null ? data.SizeMB + ' MB' : '-';
                document.getElementById('modal-version').innerText = data.Version || 'ไม่ระบุ';
                document.getElementById('modal-desc').innerText = data.Description || data.Product || 'ไม่ระบุ';
                document.getElementById('modal-created').innerText = data.Created || '-';
                document.getElementById('modal-modified').innerText = data.Modified || '-';
                document.getElementById('modal-path').innerText = data.Path || 'ไม่พบตำแหน่ง';
            }
        }).catch(err => {
            document.getElementById('modal-title').innerText = 'เกิดข้อผิดพลาดในการโหลดข้อมูล';
        });
}

function closeModal() {
    document.getElementById('detailsModal').classList.add('hidden');
}

function runFromModal() {
    if (currentModalB64) runApp(currentModalB64);
    closeModal();
}

function openFolderFromModal() {
    if (currentModalB64) openFolder(currentModalB64);
    closeModal();
}

function startScan() {
    document.getElementById('all-scan-container').innerHTML = `
        <div class="spinner mb-6"></div>
        <p class="text-slate-400 mb-2 text-lg">กำลังสแกนไฟล์ในเครื่อง... (อาจใช้เวลาหลายนาที)</p>
        <p class="text-slate-500 text-sm mb-4">พบไฟล์ทั้งหมด: <span id="scan-count" class="font-bold text-indigo-400">0</span> ไฟล์</p>
        <button onclick="stopScan()" class="bg-red-600 hover:bg-red-500 text-white font-bold py-2 px-6 rounded-lg transition-colors">ยกเลิก</button>
    `;
    
    fetch('?action=start_scan_bg')
        .then(r => r.json())
        .then(data => {
            if (data.status === 'started') {
                scanInterval = setInterval(checkScanStatus, 2000);
            }
        });
}

function checkScanStatus() {
    fetch('?action=check_scan')
        .then(r => r.json())
        .then(data => {
            const countEl = document.getElementById('scan-count');
            if (countEl) countEl.textContent = data.count;
            
            if (data.done) {
                clearInterval(scanInterval);
                loadCachedScan();
            }
        });
}

function loadCachedScan() {
    document.getElementById('all-scan-container').innerHTML = `
        <div class="spinner mb-6"></div>
        <p class="text-slate-400">กำลังประมวลผลข้อมูล...</p>
    `;
    
    fetch('?action=get_scan_data')
        .then(r => r.json())
        .then(data => {
            allExesData = data.data;
            renderAllTab();
        });
}

function renderAllTab() {
    const container = document.getElementById('content-all');
    container.innerHTML = '';
    
    if (allExesData.length === 0) {
        container.innerHTML = `
            <div class="col-span-full flex flex-col items-center justify-center py-32">
                <p class="text-slate-400 mb-6 text-lg">ไม่พบไฟล์ หรือสแกนไม่สำเร็จ</p>
                <button onclick="startScan()" class="bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-3 px-8 rounded-lg transition-colors">สแกนใหม่อีกครั้ง</button>
            </div>
        `;
        return;
    }
    
    document.getElementById('count-all').innerText = allExesData.length;
    
    const rescanBtn = document.createElement('div');
    rescanBtn.className = 'col-span-full mb-4 flex justify-end';
    rescanBtn.innerHTML = `<button onclick="startScan()" class="bg-purple-600 hover:bg-purple-500 text-white font-bold py-2 px-4 rounded-lg transition-colors text-sm flex items-center gap-2"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg> สแกนไฟล์ใหม่</button>`;
    container.appendChild(rescanBtn);

    allExesData.sort((a, b) => {
        const aFav = favArrayJs.includes(a.path);
        const bFav = favArrayJs.includes(b.path);
        if (aFav && !bFav) return -1;
        if (!aFav && bFav) return 1;
        return 0;
    });

    allExesData.forEach(item => {
        const b64 = item.b64;
        const name = item.name;
        const dir = item.dir;
        const isFav = favArrayJs.includes(item.path);
        
        const card = document.createElement('div');
        card.className = 'app-card card-hover card-bg rounded-xl overflow-hidden flex flex-col relative';
        card.innerHTML = `
            <button onclick="event.stopPropagation(); toggleLike('${b64}', this)" class="absolute top-3 right-3 text-slate-500 hover:text-pink-400 z-10 ${isFav ? 'fav-active' : ''}">
                <svg class="w-5 h-5" fill="${isFav ? 'currentColor' : 'none'}" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path></svg>
            </button>
            <div class="p-5 flex items-center justify-center h-32 bg-slate-800/30 border-b border-slate-700/50">
                <img src="?action=icon&p=${encodeURIComponent(b64)}" class="w-16 h-16 object-contain drop-shadow-2xl" alt="Icon" loading="lazy">
            </div>
            <div class="p-4 flex-grow flex flex-col">
                <h3 class="font-bold text-white text-sm mb-1 truncate" title="${name}">${name}</h3>
                <p class="text-xs text-slate-500 mb-4 truncate flex-grow" title="${dir}">${dir}</p>
                <div class="grid grid-cols-3 gap-1 mt-auto">
                    <button onclick="runApp('${b64}')" class="col-span-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold py-2 rounded transition-colors flex items-center justify-center gap-1">
                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"></path></svg>
                        PLAY
                    </button>
                    <button onclick="getDetails('${b64}')" class="bg-slate-700 hover:bg-slate-600 text-white text-xs font-bold py-2 rounded transition-colors flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </button>
                </div>
            </div>
        `;
        container.appendChild(card);
    });
}

function stopScan() {
    clearInterval(scanInterval);
    document.getElementById('all-scan-container').innerHTML = `
        <p class="text-slate-400 mb-6 text-lg">ยกเลิกการสแกนแล้ว</p>
        <button onclick="startScan()" class="bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-3 px-8 rounded-lg transition-colors text-lg">เริ่มสแกนใหม่</button>
    `;
}
</script>
</body>
</html>