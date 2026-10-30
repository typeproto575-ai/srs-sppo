<?php
/**
 * Measured 6 x 9 inch renderer for confirmation, first communion, and marriage
 * certificates. The visual language intentionally follows the baptismal stock:
 * serif typography, green parish header, fine rules, watermark, and signature
 * line instead of a separate card-style HTML template.
 */

function sacramental_certificate_date($value): string
{
    if (!$value) return '—';
    $time = strtotime((string) $value);
    return $time ? date('F j, Y', $time) : e((string) $value);
}

function sc_svg_escape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sc_svg_number(float $value): string
{
    return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
}

function sc_svg_text(string $text, float $x, float $y, float $size, float $width, array $attrs = []): string
{
    $family = $attrs['family'] ?? "'Book Antiqua','Palatino Linotype',Palatino,Georgia,serif";
    $weight = (int) ($attrs['weight'] ?? 400);
    $fill = $attrs['fill'] ?? '#222222';
    $anchor = $attrs['anchor'] ?? 'start';
    $letterSpacing = isset($attrs['letter_spacing']) ? ' letter-spacing="' . sc_svg_number((float) $attrs['letter_spacing']) . '"' : '';
    if (!empty($attrs['max'])) {
        $textLength = ' data-max-width="' . sc_svg_number($width) . '"';
    } else {
        $textLength = $width > 0 ? ' textLength="' . sc_svg_number($width) . '" lengthAdjust="spacing"' : '';
    }
    return '<text x="' . sc_svg_number($x) . '" y="' . sc_svg_number($y) . '" font-family="' . sc_svg_escape($family)
        . '" font-weight="' . $weight . '" font-size="' . sc_svg_number($size) . '" fill="' . sc_svg_escape($fill)
        . '" text-anchor="' . sc_svg_escape($anchor) . '"' . $textLength . '>' . sc_svg_escape($text) . '</text>';
}

function sc_svg_rule(float $x, float $y, float $width, string $color = '#222222', float $height = 0.183): string
{
    return '<rect x="' . sc_svg_number($x) . '" y="' . sc_svg_number($y - ($height / 2)) . '" width="'
        . sc_svg_number($width) . '" height="' . sc_svg_number($height) . '" fill="' . sc_svg_escape($color) . '"/>';
}

function sacramental_certificate_svg(string $type, array $source, array $req): string
{
    $isMarriage = $type === 'marriage';
    $isConfirmation = $type === 'confirmation';
    $title = $isMarriage ? 'HOLY MATRIMONY' : ($isConfirmation ? 'CONFIRMATION' : 'FIRST HOLY COMMUNION');
    $subject = $isMarriage
        ? trim((string) ($source['husband_full_name'] ?? $source['party_a_full_name'] ?? '') . '  &  ' . (string) ($source['wife_maiden_name'] ?? $source['party_b_full_name'] ?? ''))
        : (string) ($source[$isConfirmation ? 'confirmand_full_name' : 'communicant_full_name'] ?? '');
    $subject = cert_format_upper_case(trim($subject));
    $date = $isMarriage
        ? ($source['marriage_date'] ?? $source['date_of_marriage'] ?? null)
        : ($isConfirmation ? ($source['confirmation_date'] ?? $source['date_of_confirmation'] ?? null) : ($source['communion_date'] ?? $source['date_of_first_communion'] ?? null));
    $dateLabel = $isMarriage ? 'were united in the Sacrament of Holy Matrimony on' : ($isConfirmation ? 'received the Sacrament of Confirmation on' : 'received First Holy Communion on');
    $rows = [];
    if ($isMarriage) {
        $rows = [
            ['Husband', cert_format_title_case($source['husband_full_name'] ?? $source['party_a_full_name'] ?? '—')],
            ['Wife (maiden name)', cert_format_title_case($source['wife_maiden_name'] ?? $source['party_b_full_name'] ?? '—')],
            ['Officiating minister', cert_format_title_case($source['officiating_minister'] ?? $source['minister_name'] ?? '—')],
        ];
    } elseif ($isConfirmation) {
        $rows = [
            ['Sponsor', cert_format_title_case($source['sponsor_name'] ?? '—')],
            ['Bishop / minister', cert_format_title_case($source['minister_name'] ?? $source['officiating_minister'] ?? '—')],
            ['Father', cert_format_title_case($source['father_name'] ?? $source['father_full_name'] ?? '—')],
            ['Mother', cert_format_title_case($source['mother_maiden_name'] ?? $source['mother_full_name'] ?? '—')],
        ];
    } else {
        $rows = [
            ['Father', cert_format_title_case($source['father_name'] ?? $source['father_full_name'] ?? '—')],
            ['Mother', cert_format_title_case($source['mother_maiden_name'] ?? $source['mother_full_name'] ?? '—')],
            ['Minister', cert_format_title_case($source['minister_name'] ?? $source['officiating_minister'] ?? '—')],
        ];
    }
    $register = implode(' / ', array_filter([$source['book_no'] ?? '', $source['page_no'] ?? '', $source['line_no'] ?? ''])) ?: 'Not stated';
    $rows[] = ['Register entry', $register];

    $logo = baptismal_logo_paths();
    $green = '#6b8268';
    $ink = '#222222';
    $muted = '#565d56';
    $watermark = '#f3f7f2';
    $out = [];
    $out[] = '<svg class="sc-svg" viewBox="0 0 100 150" width="6in" height="9in" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="' . sc_svg_escape($title) . ' certificate">';
    $out[] = '<rect width="100" height="150" fill="#fff"/>';
    $out[] = '<g class="sc-template">';
    $out[] = '<g transform="translate(85.667 104.667) scale(131.31)"><path d="' . $logo['mark'] . '" fill="' . $watermark . '"/></g>';
    $out[] = '<g transform="translate(20.8 16.3) scale(20.8)" fill="' . $green . '"><path d="' . $logo['ring'] . '" fill-rule="evenodd"/><path d="' . $logo['mark'] . '"/></g>';
    $out[] = sc_svg_rule(34.667, 12.6, 10, $green, .217) . sc_svg_rule(76.833, 12.6, 10, $green, .217);
    $out[] = sc_svg_rule(34.583, 20.2, 5.5, $green, .217) . sc_svg_rule(81.417, 20.2, 5.5, $green, .217);
    $out[] = '</g>';
    $out[] = sc_svg_text('SACRAMENTAL CERTIFICATE', 33.5, 9.0, 3.4, 54, ['fill' => '#4e5051', 'letter_spacing' => .1, 'max' => true]);
    $out[] = sc_svg_text('PRELATURE OF INFANTA', 60.75, 13.4, 2.083, 30, ['family' => "'Century Gothic',Avenir,Arial,sans-serif", 'fill' => $green, 'letter_spacing' => .04, 'anchor' => 'middle', 'max' => true]);
    $out[] = sc_svg_text('ST. PATRICK PARISH', 34.5, 18.0, 4.6, 53, ['family' => "'Century Gothic',Avenir,Arial,sans-serif", 'fill' => $green, 'letter_spacing' => .04, 'max' => true]);
    $out[] = sc_svg_text('Dingalan, 3207 Aurora Province, Philippines', 40.7, 20.9, 2.354, 40.6, ['family' => 'Calibri,Arial,sans-serif', 'fill' => $muted, 'max' => true]);
    $dy = 9.2;
    $out[] = sc_svg_text('This is to certify that', 50, 19 + $dy, 3.1, 30, ['fill' => $ink, 'anchor' => 'middle', 'max' => true]);
    $subjSize = mb_strlen($subject) > 32 ? 2.5 : 3.25;
    $out[] = sc_svg_text($subject !== '' ? $subject : '?', 50, 25 + $dy, $subjSize, 78, ['fill' => '#000', 'anchor' => 'middle', 'weight' => 600, 'max' => true]);
    $out[] = sc_svg_rule(11.5, 26 + $dy, 77, $ink);
    $out[] = sc_svg_text($dateLabel, 50, 31 + $dy, 2.75, 76, ['fill' => $ink, 'anchor' => 'middle', 'max' => true]);
    $out[] = sc_svg_text(sacramental_certificate_date($date), 50, 36.5 + $dy, 3.15, 45, ['fill' => '#000', 'anchor' => 'middle', 'weight' => 600, 'max' => true]);
    $out[] = sc_svg_rule(27.5, 38 + $dy, 45, $ink);
    $out[] = sc_svg_text($title, 50, 45 + $dy, 3.65, 52, ['fill' => $ink, 'anchor' => 'middle', 'weight' => 600, 'letter_spacing' => .06, 'max' => true]);
    $out[] = sc_svg_text('According to the sacramental records of this parish', 50, 49 + $dy, 2.5, 70, ['fill' => $ink, 'anchor' => 'middle', 'max' => true]);

    $y = 57 + $dy;
    foreach ($rows as [$label, $value]) {
        $out[] = sc_svg_text($label, 12, $y, 2.05, 25, ['family' => 'Calibri,Arial,sans-serif', 'fill' => $muted, 'max' => true]);
        $out[] = sc_svg_text((string) $value, 38, $y, 2.65, 50, ['fill' => '#000', 'max' => true]);
        $out[] = sc_svg_rule(37.5, $y + .7, 52, $ink, .15);
        $y += 6.1;
    }
    $out[] = sc_svg_text('Issued this ' . date('F j, Y') . ' at St. Patrick Parish Office, Dingalan, Aurora.', 50, 88 + $dy, 2.2, 80, ['fill' => $muted, 'anchor' => 'middle', 'max' => true]);
    $out[] = sc_svg_text('Valid only with the signature of the Parish Priest and the parish seal.', 50, 91.5 + $dy, 2.2, 77, ['fill' => $muted, 'anchor' => 'middle', 'max' => true]);
    $out[] = sc_svg_rule(57.667, 103 + $dy, 35.667, $ink);
    $out[] = sc_svg_text('PARISH PRIEST', 75.5, 106 + $dy, 2.1, 27, ['family' => 'Calibri,Arial,sans-serif', 'fill' => $muted, 'anchor' => 'middle', 'letter_spacing' => .08, 'max' => true]);
    $out[] = '</svg>';
    return implode("\n", $out);
}

/** Backward-compatible entry point for certificate rendering. */
function sacramental_certificate_html(string $type, array $source, array $req, string $basePath): string
{
    return '<div class="blank-stock-print-wrapper sacramental-stock-print-wrapper" aria-label="Sacramental certificate preview">'
        . sacramental_certificate_svg($type, $source, $req)
        . '</div>';
}

function sacramental_certificate_fit_script(): string
{
    return <<<'JS'
<script>
(function () {
    function fit() {
        document.querySelectorAll('.sc-svg text[data-max-width]').forEach(function (t) {
            t.removeAttribute('textLength');
            if (!t.getComputedTextLength) return;
            var max = parseFloat(t.getAttribute('data-max-width') || '0');
            if (!max) return;
            var len = t.getComputedTextLength();
            if (len > max) {
                t.setAttribute('textLength', max.toFixed(3));
                t.setAttribute('lengthAdjust', 'spacingAndGlyphs');
            }
        });
    }
    document.addEventListener('DOMContentLoaded', fit);
    window.addEventListener('load', fit);
    if (document.fonts && document.fonts.ready) { document.fonts.ready.then(fit); }
    window.addEventListener('beforeprint', fit);
})();
</script>
JS;
}
?>
