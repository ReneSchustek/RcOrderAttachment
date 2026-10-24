<?php

declare(strict_types=1);

/*
 * Erzeugt die Testfotos der Messung: 12, 24 und 48 Megapixel, wie Smartphone-Kameras sie liefern,
 * dazu das 48-Megapixel-Foto als Hochkantaufnahme (EXIF-Ausrichtung 6). Für sie dreht der Stripper
 * das Bild, und das braucht eine zweite Kopie im Speicher.
 *
 * Fotoähnlich statt einfarbig: weiche Verläufe mit leichtem Rauschen. Eine einfarbige Fläche
 * komprimierte auf wenige Kilobyte und spräche nicht für die Dateien, die Kunden hochladen. Der
 * Zufall hat einen festen Startwert, damit jeder Rechner dieselben Bilder misst.
 *
 * Aufruf: php make-images.php <zielordner>
 */

$target = $argv[1] ?? '';
if ($target === '' || (!is_dir($target) && !mkdir($target, 0o775, true))) {
    throw new RuntimeException('Zielordner fehlt oder lässt sich nicht anlegen.');
}

$sizes = ['12mp' => [4000, 3000], '24mp' => [6000, 4000], '48mp' => [8000, 6000]];

// Ein linearer Kongruenzgenerator mit den Konstanten der C-Bibliothek: wiederholbar mit festem
// Startwert, und für leichtes Bildrauschen reicht seine Güte.
$seed = 42;
$noise = static function () use (&$seed): int {
    $seed = ($seed * 1103515245 + 12345) & 0x7FFFFFFF;

    return $seed % 25 - 12;
};

foreach ($sizes as $name => [$width, $height]) {
    $path = "{$target}/{$name}.jpg";
    if (is_file($path)) {
        continue;
    }

    $image = imagecreatetruecolor($width, $height);

    // Blöcke zu 8 × 8 Pixeln: schnell genug für 48 Megapixel, und JPEG arbeitet ohnehin in Blöcken
    // dieser Größe.
    for ($y = 0; $y < $height; $y += 8) {
        for ($x = 0; $x < $width; $x += 8) {
            $shift = $noise();
            $color = imagecolorallocate(
                $image,
                max(0, min(255, (int) (255 * $x / $width) + $shift)),
                max(0, min(255, (int) (255 * $y / $height) + $shift)),
                max(0, min(255, 128 + $shift * 4)),
            );
            if ($color === false) {
                throw new RuntimeException('GD konnte keine Farbe anlegen.');
            }
            imagefilledrectangle($image, $x, $y, $x + 7, $y + 7, $color);
        }
    }

    // 90 ist die Qualität, mit der gängige Kamera-Apps speichern.
    imagejpeg($image, $path, 90);
    printf("%s: %d x %d, %.1f MB\n", $name, $width, $height, (int) filesize($path) / 1048576);
}

// GD schreibt kein EXIF. Die Ausrichtung kommt deshalb als APP1-Segment hinter den Dateianfang:
// TIFF-Kopf in Little Endian, ein Verzeichnis mit dem Eintrag 0x0112 (SHORT, Wert 6).
$portrait = "{$target}/48mp-hochkant.jpg";
if (!is_file($portrait)) {
    $tiff = 'II' . pack('vV', 0x2A, 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, 6, 0) . pack('V', 0);
    $payload = "Exif\0\0" . $tiff;
    $jpeg = (string) file_get_contents("{$target}/48mp.jpg");
    file_put_contents($portrait, substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', \strlen($payload) + 2) . $payload . substr($jpeg, 2));
    printf("48mp-hochkant: Ausrichtung 6, %.1f MB\n", (int) filesize($portrait) / 1048576);
}
