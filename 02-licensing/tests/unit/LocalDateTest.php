<?php
/**
 * Unit tests for I18n::localDate() and the MLT harvester's date filter.
 *
 * Raw date() only ever speaks English, so every weekday/month a French
 * visitor saw was English. localDate() routes those names through the lang
 * files. Each case runs in a child PHP process: it needs SLATE_ROOT and a
 * Hook stub that registers 'fr', neither of which belongs in the shared
 * unit-runner process.
 */

declare(strict_types=1);

/** Run $body in a fresh PHP with the I18n class + an 'fr'-aware Hook stub; returns decoded JSON. */
function local_date_child(string $locale, string $body)
{
    $root = realpath(__DIR__ . '/../..');
    $prelude = '
        define("SLATE_ROOT", ' . var_export($root, true) . ');
        date_default_timezone_set("UTC");
        class Hook { public static function applyFilters($n, $v) {
            return $n === "i18n_supported_languages" ? $v + ["fr" => "Français"] : $v; } }
        require SLATE_ROOT . "/src/autoload.php";
        $_GET["lang"] = ' . var_export($locale, true) . ';
        $_SESSION = [];
        use Slate\Services\I18n\I18n;
    ';
    $script = tempnam(sys_get_temp_dir(), 'ld') . '.php';
    file_put_contents($script, "<?php\n" . $prelude . $body);
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
    @unlink($script);
    $decoded = json_decode((string)$out, true);
    if ($decoded === null) throw new RuntimeException('child failed: ' . $out);
    return $decoded;
}

// Friday 18 September 2026, 15:00 UTC
const LD_TS = 1789743600;

unit('localDate matches date() exactly in English', function () {
    $formats = ['l, j F Y', 'D j M', 'D, j M Y \a\t g:i a', 'j M Y, H:i', 'M j, Y g:i a', 'D · j M Y', 'F Y', 'jS \o\f F'];
    $got = local_date_child('en', '
        $r = [];
        foreach (' . var_export($formats, true) . ' as $f) $r[$f] = [I18n::localDate($f, ' . LD_TS . '), date($f, ' . LD_TS . ')];
        echo json_encode($r);
    ');
    foreach ($got as $fmt => [$local, $raw]) assert_eq($raw, $local, "format '$fmt'");
});

unit('localDate renders French weekday and month names', function () {
    $got = local_date_child('fr', '
        echo json_encode([
            I18n::localDate("l, j F Y, H:i", ' . LD_TS . '),
            I18n::localDate("D j M", ' . LD_TS . '),
        ]);
    ');
    assert_eq('vendredi, 18 septembre 2026, 15:00', $got[0]);
    assert_eq('ven 18 sept.', $got[1]);
});

unit('localDate uses real French short months, so juin and juillet differ', function () {
    $got = local_date_child('fr', '
        echo json_encode([
            I18n::localDate("j M Y", mktime(12, 0, 0, 6, 15, 2026)),
            I18n::localDate("j M Y", mktime(12, 0, 0, 7, 15, 2026)),
            I18n::localDate("j M", mktime(12, 0, 0, 2, 3, 2026)),
            I18n::localDate("j M", mktime(12, 0, 0, 5, 3, 2026)),
        ]);
    ');
    assert_eq(['15 juin 2026', '15 juil. 2026', '3 févr.', '3 mai'], $got);
});

unit('localDate still passes time tokens through date()', function () {
    $got = local_date_child('fr', 'echo json_encode(I18n::localDate("H:i g:ia", ' . LD_TS . '));');
    assert_eq('15:00 3:00pm', $got);
});

unit('harvester skips rendered dates but keeps ordinary copy', function () {
    $got = local_date_child('en', '
        require SLATE_ROOT . "/plugins/multilang-translate/includes/Harvester.php";
        $m = new ReflectionMethod("MLT_Harvester", "looksLikeFormattedDate");
        $r = [];
        foreach ([
            "Fri 18 Sep", "Friday, 11 September 2026, 15:00", "Sep 11, 1:01am",
            "vendredi, 18 septembre 2026, 15:00", "1er mai", "15 juil. 2026", "ven 18 sept.",
            "You may book up to 3 sessions", "Voir mon profil (2)", "Book now", "3 items",
        ] as $s) $r[$s] = $m->invoke(null, $s);
        echo json_encode($r);
    ');
    foreach (['Fri 18 Sep', 'Friday, 11 September 2026, 15:00', 'Sep 11, 1:01am',
              'vendredi, 18 septembre 2026, 15:00', '1er mai', '15 juil. 2026', 'ven 18 sept.'] as $s) {
        assert_true($got[$s], "should skip '$s'");
    }
    foreach (['You may book up to 3 sessions', 'Voir mon profil (2)', 'Book now', '3 items'] as $s) {
        assert_false($got[$s], "should keep '$s'");
    }
});
