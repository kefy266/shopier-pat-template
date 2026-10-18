<?php
/**
 * Bağımlılıksız, küçük PDF üretici (fatura belgeleri için).
 *  - TrueType yazı tipini alt küme (subset) olarak gömer: Türkçe dahil tüm Unicode karakterler,
 *    metin kopyalanabilir (ToUnicode). Örn. Noto Sans, DejaVu Sans, Liberation Sans.
 *  - Logo, tek yollu (M/L/C/Z) bir SVG dosyasından vektör olarak çizilebilir (isteğe bağlı).
 */
declare(strict_types=1);

final class PdfTtf
{
    public int $upm;
    public array $bbox;
    public int $ascent;
    public int $descent;
    public int $capHeight;
    /** @var array<int,int> unicode => glyph */
    public array $cmap = [];
    /** @var int[] */
    public array $genislik = [];
    /** @var array<int,int> kullanılan glif => unicode */
    public array $kullanilan = [0 => 0];
    private string $veri;
    private array $tablo = [];
    private array $loca = [];

    public function __construct(public string $dosya, public string $ad)
    {
        $this->veri = (string) file_get_contents($dosya);
        $n = $this->u16(4);
        for ($i = 0; $i < $n; $i++) {
            $p = 12 + $i * 16;
            $this->tablo[substr($this->veri, $p, 4)] = [$this->u32($p + 8), $this->u32($p + 12)];
        }
        $h = $this->tablo['head'][0];
        $this->upm = $this->u16($h + 18);
        $this->bbox = [$this->s16($h + 36), $this->s16($h + 38), $this->s16($h + 40), $this->s16($h + 42)];
        $locaUzun = $this->s16($h + 50) === 1;
        $hh = $this->tablo['hhea'][0];
        $this->ascent = $this->s16($hh + 4);
        $this->descent = $this->s16($hh + 6);
        $nh = $this->u16($hh + 34);
        $ng = $this->u16($this->tablo['maxp'][0] + 4);
        $this->capHeight = isset($this->tablo['OS/2']) && $this->tablo['OS/2'][1] >= 90 ? $this->s16($this->tablo['OS/2'][0] + 88) : $this->ascent;
        $hm = $this->tablo['hmtx'][0];
        $son = 0;
        for ($g = 0; $g < $ng; $g++) {
            if ($g < $nh) $son = $this->u16($hm + $g * 4);
            $this->genislik[$g] = $son;
        }
        $l = $this->tablo['loca'][0];
        for ($g = 0; $g <= $ng; $g++) $this->loca[$g] = $locaUzun ? $this->u32($l + $g * 4) : $this->u16($l + $g * 2) * 2;
        $this->cmapOku();
    }

    private function u16(int $p): int { return unpack('n', $this->veri, $p)[1]; }
    private function s16(int $p): int { $v = $this->u16($p); return $v >= 0x8000 ? $v - 0x10000 : $v; }
    private function u32(int $p): int { return unpack('N', $this->veri, $p)[1]; }

    private function cmapOku(): void
    {
        $c = $this->tablo['cmap'][0];
        $n = $this->u16($c + 2);
        $sec = null;
        for ($i = 0; $i < $n; $i++) {
            $pl = $this->u16($c + 4 + $i * 8); $en = $this->u16($c + 6 + $i * 8); $of = $this->u32($c + 8 + $i * 8);
            if ($pl === 3 && $en === 1) $sec = $c + $of;
        }
        if ($sec === null || $this->u16($sec) !== 4) throw new RuntimeException('Desteklenmeyen cmap');
        $seg = $this->u16($sec + 6) / 2;
        $sonK = $sec + 14; $basK = $sonK + $seg * 2 + 2; $deltaK = $basK + $seg * 2; $aralikK = $deltaK + $seg * 2;
        for ($i = 0; $i < $seg; $i++) {
            $bas = $this->u16($basK + $i * 2); $sonu = $this->u16($sonK + $i * 2);
            $delta = $this->u16($deltaK + $i * 2); $aralik = $this->u16($aralikK + $i * 2);
            if ($bas === 0xFFFF) continue;
            for ($k = $bas; $k <= $sonu; $k++) {
                if ($aralik === 0) $g = ($k + $delta) & 0xFFFF;
                else {
                    $g = $this->u16($aralikK + $i * 2 + $aralik + ($k - $bas) * 2);
                    if ($g) $g = ($g + $delta) & 0xFFFF;
                }
                if ($g) $this->cmap[$k] = $g;
            }
        }
    }

    /** Metni glif dizisine çevirir ve kullanılan glifleri işaretler. */
    public function glifler(string $metin): array
    {
        $out = [];
        foreach (mb_str_split($metin, 1, 'UTF-8') as $ch) {
            $u = mb_ord($ch, 'UTF-8');
            $g = $this->cmap[$u] ?? 0;
            $this->kullanilan[$g] = $u;
            $out[] = $g;
        }
        return $out;
    }

    public function genislik(string $metin, float $boyut): float
    {
        $t = 0;
        foreach (mb_str_split($metin, 1, 'UTF-8') as $ch) $t += $this->genislik[$this->cmap[mb_ord($ch, 'UTF-8')] ?? 0] ?? 0;
        return $t * $boyut / $this->upm;
    }

    /** Yalnızca kullanılan glifleri içeren yazı tipi (glif kimlikleri korunur). */
    public function altKume(): string
    {
        $glyf = $this->tablo['glyf'][0];
        $gerekli = array_keys($this->kullanilan);
        for ($i = 0; $i < count($gerekli); $i++) {          // bileşik gliflerin parçaları
            $g = $gerekli[$i];
            $bas = $this->loca[$g]; $uz = $this->loca[$g + 1] - $bas;
            if ($uz <= 0 || $this->s16($glyf + $bas) >= 0) continue;
            $p = $glyf + $bas + 10;
            do {
                $bayrak = $this->u16($p); $parca = $this->u16($p + 2);
                if (!in_array($parca, $gerekli, true)) $gerekli[] = $parca;
                $p += 4 + (($bayrak & 1) ? 4 : 2) + (($bayrak & 8) ? 2 : (($bayrak & 0x40) ? 4 : (($bayrak & 0x80) ? 8 : 0)));
            } while ($bayrak & 0x20);
        }
        $gerekli = array_flip($gerekli);
        $yeniGlyf = ''; $yeniLoca = '';
        $ng = count($this->loca) - 1;
        for ($g = 0; $g < $ng; $g++) {
            $yeniLoca .= pack('N', strlen($yeniGlyf));
            if (isset($gerekli[$g])) {
                $parca = substr($this->veri, $glyf + $this->loca[$g], $this->loca[$g + 1] - $this->loca[$g]);
                $yeniGlyf .= $parca . str_repeat("\0", (4 - strlen($parca) % 4) % 4);
            }
        }
        $yeniLoca .= pack('N', strlen($yeniGlyf));
        $tablolar = [];
        foreach (['cvt ', 'fpgm', 'glyf', 'head', 'hhea', 'hmtx', 'loca', 'maxp', 'prep'] as $t) {
            if ($t === 'glyf') { $tablolar[$t] = $yeniGlyf; continue; }
            if ($t === 'loca') { $tablolar[$t] = $yeniLoca; continue; }
            if (!isset($this->tablo[$t])) continue;
            $tablolar[$t] = substr($this->veri, $this->tablo[$t][0], $this->tablo[$t][1]);
        }
        $tablolar['head'] = substr_replace($tablolar['head'], "\0\0\0\0", 8, 4);   // checkSumAdjustment
        $tablolar['head'] = substr_replace($tablolar['head'], pack('n', 1), 50, 2);  // uzun loca
        $n = count($tablolar);
        $es = 2 ** (int) floor(log($n, 2));
        $bas = pack('Nnnnn', 0x00010000, $n, $es * 16, (int) log($es, 2), $n * 16 - $es * 16);
        $dizin = ''; $govde = ''; $ofset = 12 + 16 * $n;
        foreach ($tablolar as $t => $v) {
            $dizin .= $t . pack('NNN', self::toplam($v), $ofset + strlen($govde), strlen($v));
            $govde .= $v . str_repeat("\0", (4 - strlen($v) % 4) % 4);
        }
        return $bas . $dizin . $govde;
    }

    private static function toplam(string $v): int
    {
        $v .= str_repeat("\0", (4 - strlen($v) % 4) % 4);
        $t = 0;
        foreach (unpack('N*', $v) as $x) $t = ($t + $x) & 0xFFFFFFFF;
        return $t;
    }
}

final class SimplePdf
{
    public const G = 595.28;
    public const Y = 841.89;
    private array $sayfalar = [];
    private string $akim = '';
    /** @var PdfTtf[] */
    private array $fontlar = [];

    public function __construct(string $normal, string $kalin)
    {
        $this->fontlar['N'] = new PdfTtf($normal, 'InvoiceSans');
        $this->fontlar['B'] = new PdfTtf($kalin, 'InvoiceSans-Bold');
        $this->sayfaEkle();
    }

    public function sayfaEkle(): void
    {
        if ($this->akim !== '') $this->sayfalar[] = $this->akim;
        $this->akim = ' ';
    }

    private static function f(float $n): string { return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.') ?: '0'; }

    private static function renk(string $hex, bool $dolgu = true): string
    {
        $h = ltrim($hex, '#');
        [$r, $g, $b] = [hexdec(substr($h, 0, 2)) / 255, hexdec(substr($h, 2, 2)) / 255, hexdec(substr($h, 4, 2)) / 255];
        return self::f($r) . ' ' . self::f($g) . ' ' . self::f($b) . ($dolgu ? ' rg' : ' RG');
    }

    public function genislik(string $m, float $boyut, bool $kalin = false): float
    {
        return $this->fontlar[$kalin ? 'B' : 'N']->genislik($m, $boyut);
    }

    /** Metin yazar. $y üstten ölçülür (taban çizgisi). $hiza: L | R | C */
    public function metin(float $x, float $y, string $m, float $boyut = 10, bool $kalin = false, string $renk = '#0a0a0a', string $hiza = 'L'): void
    {
        if ($m === '') return;
        $f = $this->fontlar[$kalin ? 'B' : 'N'];
        $w = $f->genislik($m, $boyut);
        if ($hiza === 'R') $x -= $w; elseif ($hiza === 'C') $x -= $w / 2;
        $hex = '';
        foreach ($f->glifler($m) as $g) $hex .= sprintf('%04X', $g);
        $this->akim .= 'BT ' . self::renk($renk) . ' /F' . ($kalin ? 'B' : 'N') . ' ' . self::f($boyut) . ' Tf '
                     . self::f($x) . ' ' . self::f(self::Y - $y) . " Td <$hex> Tj ET\n";
    }

    /** Metni verilen genişlikte satırlara böler. */
    public function sar(string $m, float $gen, float $boyut, bool $kalin = false): array
    {
        $satirlar = [];
        foreach (preg_split('/\R/u', $m) as $par) {
            $s = '';
            foreach (preg_split('/\s+/u', trim($par)) as $k) {
                $aday = $s === '' ? $k : "$s $k";
                if ($s !== '' && $this->genislik($aday, $boyut, $kalin) > $gen) { $satirlar[] = $s; $s = $k; }
                else $s = $aday;
            }
            $satirlar[] = $s;
        }
        return $satirlar;
    }

    public function cizgi(float $x1, float $y1, float $x2, float $y2, string $renk = '#e5e5e5', float $kalinlik = .6): void
    {
        $this->akim .= self::renk($renk, false) . ' ' . self::f($kalinlik) . ' w ' . self::f($x1) . ' ' . self::f(self::Y - $y1) . ' m '
                     . self::f($x2) . ' ' . self::f(self::Y - $y2) . " l S\n";
    }

    public function kutu(float $x, float $y, float $g, float $y2, string $dolgu = '#f5f5f5', ?string $kenar = null): void
    {
        $this->akim .= self::renk($dolgu) . ($kenar ? ' ' . self::renk($kenar, false) . ' .6 w' : '') . ' '
                     . self::f($x) . ' ' . self::f(self::Y - $y - $y2) . ' ' . self::f($g) . ' ' . self::f($y2) . ' re ' . ($kenar ? 'B' : 'f') . "\n";
    }

    /** SVG dosyasındaki ilk yolu (M/L/C/Z, mutlak) vektör olarak çizer. */
    public function svgYol(string $svgDosya, float $x, float $y, float $yukseklik, string $renk = '#0a0a0a'): void
    {
        $svg = (string) @file_get_contents($svgDosya);
        if (!preg_match('/viewBox="[\d.\s-]+?\s([\d.]+)\s([\d.]+)"/', $svg, $vb) || !preg_match('/\sd="([^"]+)"/', $svg, $d)) return;
        $s = $yukseklik / (float) $vb[2];
        $yol = '';
        preg_match_all('/([MLCZ])([^MLCZ]*)/i', $d[1], $par, PREG_SET_ORDER);
        foreach ($par as [, $k, $sayi]) {
            $n = array_map('floatval', preg_split('/[\s,]+/', trim($sayi), -1, PREG_SPLIT_NO_EMPTY));
            $op = ['M' => 'm', 'L' => 'l', 'C' => 'c', 'Z' => 'h'][strtoupper($k)];
            $yol .= ($n ? implode(' ', array_map([self::class, 'f'], $n)) . ' ' : '') . $op . ' ';
        }
        $this->akim .= 'q ' . self::renk($renk) . ' ' . self::f($s) . ' 0 0 ' . self::f(-$s) . ' ' . self::f($x) . ' ' . self::f(self::Y - $y)
                     . ' cm ' . $yol . "f* Q\n";
    }

    public function cikti(array $bilgi = []): string
    {
        $this->sayfalar[] = $this->akim;
        $this->akim = '';
        $nesne = [];
        $ekle = function (string $icerik) use (&$nesne): int { $nesne[] = $icerik; return count($nesne); };
        $fontRef = [];
        foreach ($this->fontlar as $k => $f) {
            $ttf = gzcompress($f->altKume());
            $dosya = $ekle("<< /Length " . strlen($ttf) . " /Filter /FlateDecode >>\nstream\n$ttf\nendstream");
            $o = 1000 / $f->upm;
            $tanim = $ekle('<< /Type /FontDescriptor /FontName /' . $f->ad . ' /Flags 32 /FontBBox [' . implode(' ', array_map(fn($v) => (int) round($v * $o), $f->bbox))
                . '] /ItalicAngle 0 /Ascent ' . (int) round($f->ascent * $o) . ' /Descent ' . (int) round($f->descent * $o)
                . ' /CapHeight ' . (int) round($f->capHeight * $o) . ' /StemV 80 /FontFile2 ' . $dosya . ' 0 R >>');
            ksort($f->kullanilan);
            $w = '';
            $cmap = '';
            foreach ($f->kullanilan as $g => $u) {
                $w .= $g . ' [' . (int) round(($f->genislik[$g] ?? 0) * $o) . '] ';
                if ($u) $cmap .= sprintf("<%04X> <%04X>\n", $g, $u);
            }
            $cid = $ekle('<< /Type /Font /Subtype /CIDFontType2 /BaseFont /' . $f->ad . ' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>'
                . ' /FontDescriptor ' . $tanim . ' 0 R /W [' . $w . '] /CIDToGIDMap /Identity >>');
            $tu = "/CIDInit /ProcSet findresource begin 12 dict begin begincmap /CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
                . "/CMapName /Adobe-Identity-UCS def /CMapType 2 def 1 begincodespacerange <0000> <FFFF> endcodespacerange\n"
                . count(array_filter($f->kullanilan)) . " beginbfchar\n$cmap endbfchar endcmap CMapName currentdict /CMap defineresource pop end end";
            $tuRef = $ekle("<< /Length " . strlen($tu) . " >>\nstream\n$tu\nendstream");
            $fontRef[$k] = $ekle('<< /Type /Font /Subtype /Type0 /BaseFont /' . $f->ad . ' /Encoding /Identity-H /DescendantFonts [' . $cid . ' 0 R] /ToUnicode ' . $tuRef . ' 0 R >>');
        }
        $kaynak = '<< /Font << /FN ' . $fontRef['N'] . ' 0 R /FB ' . $fontRef['B'] . ' 0 R >> >>';
        $sayfaKok = count($nesne) + 1 + count($this->sayfalar) * 2;
        $kids = [];
        foreach ($this->sayfalar as $a) {
            $z = gzcompress($a);
            $ak = $ekle("<< /Length " . strlen($z) . " /Filter /FlateDecode >>\nstream\n$z\nendstream");
            $kids[] = $ekle("<< /Type /Page /Parent $sayfaKok 0 R /MediaBox [0 0 " . self::G . ' ' . self::Y . "] /Resources $kaynak /Contents $ak 0 R >>") . ' 0 R';
        }
        $ekle('<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>');
        $esc = fn($s) => '(' . strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')';
        $utf16 = fn($s) => '<FEFF' . strtoupper(bin2hex(mb_convert_encoding($s, 'UTF-16BE', 'UTF-8'))) . '>';
        $info = $ekle('<< /Producer ' . $esc('shopier-pat-template') . ' /Title ' . $utf16($bilgi['baslik'] ?? 'Belge')
            . ' /Author ' . $utf16($bilgi['yazar'] ?? '') . ' /CreationDate ' . $esc('D:' . date('YmdHis')) . ' >>');
        $katalog = $ekle("<< /Type /Catalog /Pages $sayfaKok 0 R >>");
        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $ofs = [];
        foreach ($nesne as $i => $icerik) {
            $ofs[$i + 1] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n$icerik\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($nesne) + 1) . "\n0000000000 65535 f \n";
        foreach ($ofs as $o) $pdf .= sprintf("%010d 00000 n \n", $o);
        return $pdf . "trailer\n<< /Size " . (count($nesne) + 1) . " /Root $katalog 0 R /Info $info 0 R >>\nstartxref\n$xref\n%%EOF";
    }
}

/** Türkçe büyük harf (i → İ, ı → I). */
function tr_upper(string $s): string
{
    return mb_strtoupper(strtr($s, ['i' => 'İ', 'ı' => 'I']), 'UTF-8');
}

/**
 * Fatura belgesi (A4).
 * $v: no, tarih, durum, alt_baslik, satici[satır], alici[satır], kalemler[[ad, detay, adet, birim, tutar]],
 *     toplamlar[[etiket, tutar]] (son satır genel toplam), odeme[[etiket, değer]], not, logo (svg yolu), fontlar [normal, kalın]
 */
function invoice_pdf(array $v): string
{
    $fd = $v['fontlar'];
    $p = new SimplePdf($fd[0], $fd[1]);
    $sol = 48; $sag = SimplePdf::G - 48; $gen = $sag - $sol;
    $koyu = '#0a0a0a'; $gri = '#737373'; $acik = '#e5e5e5';

    // Üst bilgi
    if (!empty($v['logo'])) $p->svgYol($v['logo'], $sol, 50, 26, $koyu);
    $p->metin($sol + (!empty($v['logo']) ? 34 : 0), 64, $v['satici'][0] ?? '', 14, true);
    $p->metin($sol + (!empty($v['logo']) ? 34 : 0), 77, $v['alt_baslik'] ?? '', 8.5, false, $gri);
    $p->metin($sag, 64, 'FATURA', 20, true, $koyu, 'R');
    $p->metin($sag, 80, $v['no'], 9.5, false, $gri, 'R');

    // Belge bilgileri şeridi
    $y = 112;
    $p->kutu($sol, $y, $gen, 46, '#fafafa', $acik);
    $alanlar = [['Fatura no', $v['no']], ['Düzenlenme tarihi', $v['tarih']], ['Durum', $v['durum']]];
    if (!empty($v['ek_alan'])) $alanlar[] = $v['ek_alan'];
    $kol = $gen / count($alanlar);
    foreach ($alanlar as $i => [$et, $de]) {
        $x = $sol + 14 + $i * $kol;
        $p->metin($x, $y + 18, tr_upper($et), 7, false, $gri);
        $p->metin($x, $y + 34, $de, 10, true, $et === 'Durum' && $de === 'ÖDENDİ' ? '#15803d' : $koyu);
    }

    // Satıcı / Alıcı
    $y = 190;
    $altY = [];
    foreach ([['SATICI', $v['satici'], $sol], ['ALICI', $v['alici'], $sol + $gen / 2 + 10]] as [$et, $satirlar, $x]) {
        $p->metin($x, $y, $et, 7, false, $gri);
        $yy = $y + 16;
        foreach (array_values(array_filter($satirlar, fn($s) => trim((string) $s) !== '')) as $i => $s) {
            foreach ($p->sar((string) $s, $gen / 2 - 14, 9.5, $i === 0) as $parca) {
                $p->metin($x, $yy, $parca, $i === 0 ? 10.5 : 9.5, $i === 0, $i === 0 ? $koyu : '#3f3f3f');
                $yy += $i === 0 ? 15 : 13;
            }
        }
        $altY[] = $yy;
    }

    // Kalemler tablosu
    $y = max($altY) + 22;
    $kAdet = $sag - 200; $kBirim = $sag - 100; $kTutar = $sag;
    $p->kutu($sol, $y, $gen, 24, '#f5f5f5');
    $p->metin($sol + 10, $y + 15.5, 'AÇIKLAMA', 7.5, true, $gri);
    $p->metin($kAdet, $y + 15.5, 'ADET', 7.5, true, $gri, 'R');
    $p->metin($kBirim, $y + 15.5, 'BİRİM FİYAT', 7.5, true, $gri, 'R');
    $p->metin($kTutar - 10, $y + 15.5, 'TUTAR', 7.5, true, $gri, 'R');
    $y += 24;
    foreach ($v['kalemler'] as [$ad, $detay, $adet, $birim, $tutar]) {
        if ($y > SimplePdf::Y - 200) { $p->sayfaEkle(); $y = 60; }
        $satir = $p->sar($ad, $kAdet - $sol - 60, 10, true);
        $dsatir = $detay !== '' ? $p->sar($detay, $kAdet - $sol - 60, 8.5) : [];
        $yy = $y + 18;
        foreach ($satir as $s) { $p->metin($sol + 10, $yy, $s, 10, true); $yy += 13; }
        foreach ($dsatir as $s) { $p->metin($sol + 10, $yy, $s, 8.5, false, $gri); $yy += 11.5; }
        $p->metin($kAdet, $y + 18, (string) $adet, 10, false, $koyu, 'R');
        $p->metin($kBirim, $y + 18, $birim, 10, false, $koyu, 'R');
        $p->metin($kTutar - 10, $y + 18, $tutar, 10, true, $koyu, 'R');
        $y = $yy + 6;
        $p->cizgi($sol, $y, $sag, $y, $acik);
    }

    // Toplamlar
    $y += 20;
    $ts = $v['toplamlar'];
    $gt = array_pop($ts);
    foreach ($ts as [$et, $de]) {
        $p->metin($kBirim - 40, $y, $et, 9.5, false, $gri, 'R');
        $p->metin($kTutar - 10, $y, $de, 9.5, false, $koyu, 'R');
        $y += 18;
    }
    $p->kutu($kBirim - 150, $y - 8, $sag - ($kBirim - 150), 32, $koyu);
    $p->metin($kBirim - 136, $y + 12.5, $gt[0], 10, true, '#ffffff');
    $p->metin($kTutar - 10, $y + 13, $gt[1], 13, true, '#ffffff', 'R');
    $y += 56;

    // Ödeme bilgileri
    if (!empty($v['odeme'])) {
        $p->metin($sol, $y, 'ÖDEME BİLGİLERİ', 7, false, $gri);
        $y += 16;
        foreach ($v['odeme'] as [$et, $de]) {
            $p->metin($sol, $y, $et, 9.5, false, $gri);
            $p->metin($sol + 120, $y, $de, 9.5, false, $koyu);
            $y += 14;
        }
    }

    // Alt bilgi
    $alt = SimplePdf::Y - 70;
    $p->cizgi($sol, $alt, $sag, $alt, $acik);
    $yy = $alt + 16;
    foreach ($p->sar($v['not'] ?? '', $gen, 8) as $s) { $p->metin($sol, $yy, $s, 8, false, $gri); $yy += 11; }
    return $p->cikti(['baslik' => 'Fatura ' . $v['no'], 'yazar' => $v['satici'][0] ?? '']);
}
