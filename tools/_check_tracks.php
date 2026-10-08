<?php
/** 临时验收脚本：打印某集的字幕轨与播放接口里的字幕清单。 */
require '/opt/anime-new/backend/src/bootstrap.php';

$ep = (int) ($argv[1] ?? 80);
echo "=== episode_subtitle 表 ===\n";
foreach (App\Service\SubtitleService::tracks($ep) as $t) {
    printf(
        "  #%d  kind=%-6s codec=%-20s lang=%-6s cues=%-4d bytes=%-9d file=%s\n",
        $t['track_index'], $t['kind'], $t['codec'], (string) $t['language'],
        $t['cues'], $t['bytes'], $t['file']
    );
    printf("       label=%s\n", (string) $t['label']);
}

echo "\n=== 播放接口里的 subtitles ===\n";
$ctl = new App\Controller\AnimeController();
$r = new ReflectionMethod($ctl, 'subtitlePayload');
$r->setAccessible(true);
foreach ($r->invoke($ctl, $ep) as $x) {
    printf("  #%d kind=%-6s url=%s\n", $x['index'], $x['kind'], $x['url']);
}

echo "\n=== 资产路径解析 ===\n";
$m = null;
foreach (App\Service\SubtitleService::tracks($ep) as $t) {
    if ($t['kind'] === 'image') {
        $m = $t;
        break;
    }
}
if ($m) {
    $i = (int) $m['track_index'];
    foreach (['manifest.json', '00001.webp', '../../../etc/passwd', 'x.webp'] as $f) {
        $p = App\Service\SubtitleService::assetPath($ep, $i, $f);
        printf("  %-24s -> %s\n", $f, $p === null ? 'null（已拒绝）' : $p);
    }
}
