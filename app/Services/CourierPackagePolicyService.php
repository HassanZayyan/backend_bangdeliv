<?php

namespace App\Services;

class CourierPackagePolicyService
{
    public const STATUS_ALLOWED = 'ALLOWED';
    public const STATUS_NEEDS_CLARIFICATION = 'NEEDS_CLARIFICATION';
    public const STATUS_PROHIBITED = 'PROHIBITED';
    public const STATUS_OVERSIZE = 'OVERSIZE';

    private const MAX_WEIGHT_KG = 10.0;
    private const MAX_DIMENSION_CM = 40;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function evaluate(array $payload): array
    {
        $description = $this->normalizeText($payload['package_description'] ?? null);
        $message = $this->normalizeText($payload['message'] ?? null);
        $packingNote = $this->normalizeText($payload['packing_note'] ?? null);

        $text = strtolower(trim($description.' '.$message.' '.$packingNote));
        $weightKg = $this->resolveWeightKg($payload, $text);
        $dimensions = $this->resolveDimensionsCm($payload, $text);
        $flags = [];
        $reasons = [];
        $isKnownSmallSafeItem = $this->isKnownSmallSafeItem($description);

        if ($description === null || $this->isGenericPackageDescription($description)) {
            $flags[] = 'GENERIC_DESCRIPTION';
            $reasons[] = 'Isi paket belum spesifik. Tulis nama barang yang akan dikirim.';
        }

        foreach ($this->matchedKeywords($text, $this->prohibitedKeywords()) as $flag => $keyword) {
            $flags[] = $flag;
            $reasons[] = 'Barang terindikasi termasuk kategori terlarang: '.$keyword.'.';
        }

        foreach ($this->matchedKeywords($text, $this->oversizeKeywords()) as $flag => $keyword) {
            $flags[] = $flag;
            $reasons[] = 'Barang terindikasi besar/berat: '.$keyword.'. Driver dapat menyesuaikan ongkir atau memakai bantuan 2 orang.';
        }

        if ($weightKg !== null && $weightKg > self::MAX_WEIGHT_KG) {
            $flags[] = 'OVER_WEIGHT';
            $reasons[] = sprintf('Estimasi berat %.1f kg perlu penanganan ekstra; driver dapat menyesuaikan ongkir atau memakai bantuan 2 orang.', $weightKg);
        }

        $dimensionValues = array_filter([
            $dimensions['length_cm'],
            $dimensions['width_cm'],
            $dimensions['height_cm'],
        ], fn (?int $value): bool => $value !== null);

        if ($dimensionValues !== [] && max($dimensionValues) > self::MAX_DIMENSION_CM) {
            $flags[] = 'OVER_DIMENSION';
            $reasons[] = 'Ukuran paket perlu penanganan ekstra; driver dapat menyesuaikan ongkir atau memakai bantuan 2 orang.';
        }

        foreach ($this->matchedKeywords($text, $this->clarificationKeywords()) as $flag => $keyword) {
            $flags[] = $flag;
            $reasons[] = 'Barang perlu klarifikasi/packing sebelum dikirim: '.$keyword.'.';
        }

        if (
            $isKnownSmallSafeItem &&
            $weightKg === null &&
            $dimensions['length_cm'] === null &&
            $dimensions['width_cm'] === null &&
            $dimensions['height_cm'] === null
        ) {
            $flags[] = 'SIZE_INFERRED_SMALL';
        }

        if ($this->needsSizeClarification($description, $weightKg, $dimensions)) {
            $flags[] = 'SIZE_UNCLEAR';
            $reasons[] = 'Estimasi berat atau ukuran paket belum jelas.';
        }

        $flags = array_values(array_unique($flags));
        $status = $this->resolveStatus($flags);

        return [
            'safety_status' => $status,
            'safety_flags' => $flags,
            'safety_reason' => $reasons === [] ? 'Paket aman untuk layanan kurir motor.' : implode(' ', array_values(array_unique($reasons))),
            'size_class' => $this->resolveSizeClass($weightKg, $dimensions, $flags),
            'estimated_weight_kg' => $weightKg,
            'package_length_cm' => $dimensions['length_cm'],
            'package_width_cm' => $dimensions['width_cm'],
            'package_height_cm' => $dimensions['height_cm'],
            'packing_note' => $packingNote,
            'max_weight_kg' => self::MAX_WEIGHT_KG,
            'max_dimension_cm' => self::MAX_DIMENSION_CM,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function prohibitedKeywords(): array
    {
        return [
            'PROHIBITED_DRUGS' => 'narkoba|narkotika|psikotropika|sabu|ganja|ekstasi|obat terlarang',
            'PROHIBITED_WEAPON' => 'senjata|pistol|senapan|amunisi|peluru|bom|bahan peledak|petasan|kembang api',
            'PROHIBITED_FLAMMABLE' => 'bensin|solar|bbm|minyak tanah|korek api|gas|elpiji|lpg|aerosol|cat semprot|thinner',
            'PROHIBITED_CHEMICAL' => 'asam kuat|basa kuat|korosif|racun|pestisida|merkuri|sianida|radioaktif',
            'PROHIBITED_BIOHAZARD' => 'limbah medis|darah|virus|bakteri|sampel biologis|infeksius',
            'PROHIBITED_LIVE_ANIMAL' => 'hewan hidup|kucing|anjing|burung hidup|ikan hidup|ayam hidup',
            'PROHIBITED_VALUABLE' => 'uang tunai|emas|perhiasan|berlian|permata|surat berharga',
            'PROHIBITED_ILLEGAL' => 'barang ilegal|pornografi|barang curian',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function oversizeKeywords(): array
    {
        return [
            'OVERSIZE_FURNITURE' => 'lemari|kasur|sofa|meja besar|kursi besar|rak besar',
            'OVERSIZE_APPLIANCE' => 'kulkas|mesin cuci|ac outdoor|kompor besar',
            'OVERSIZE_VEHICLE_PART' => 'sepeda|ban mobil|velg mobil|mesin motor|mesin berat',
            'OVERSIZE_GALLON' => 'galon banyak|banyak galon|beberapa galon',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function clarificationKeywords(): array
    {
        return [
            'CLARIFY_LIQUID' => 'cairan|minuman|saus|minyak|parfum',
            'CLARIFY_PERISHABLE' => 'makanan basah|makanan segar|beku|daging|ikan|buah|sayur',
            'CLARIFY_FRAGILE' => 'fragile|mudah pecah|pecah belah|kaca|gelas|keramik',
            'CLARIFY_ELECTRONIC' => 'elektronik|laptop|kamera|handphone|monitor',
        ];
    }

    /**
     * @param  array<string, string>  $patterns
     * @return array<string, string>
     */
    private function matchedKeywords(string $text, array $patterns): array
    {
        if ($text === '') {
            return [];
        }

        $matches = [];
        foreach ($patterns as $flag => $pattern) {
            if (preg_match('/\b(?:'.$pattern.')\b/iu', $text, $match) === 1) {
                $matches[$flag] = $match[0];
            }
        }

        return $matches;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveWeightKg(array $payload, string $text): ?float
    {
        $raw = $payload['estimated_weight_kg'] ?? $payload['weight_kg'] ?? null;
        if (is_numeric($raw)) {
            return round(max(0.0, (float) $raw), 2);
        }

        if (preg_match('/(\d+(?:[,.]\d+)?)\s*(kg|kilogram)\b/iu', $text, $match) === 1) {
            return round((float) str_replace(',', '.', $match[1]), 2);
        }

        if (preg_match('/(\d+(?:[,.]\d+)?)\s*(gr|gram)\b/iu', $text, $match) === 1) {
            return round(((float) str_replace(',', '.', $match[1])) / 1000, 2);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{length_cm: ?int, width_cm: ?int, height_cm: ?int}
     */
    private function resolveDimensionsCm(array $payload, string $text): array
    {
        $length = $this->toPositiveInt($payload['package_length_cm'] ?? $payload['length_cm'] ?? null);
        $width = $this->toPositiveInt($payload['package_width_cm'] ?? $payload['width_cm'] ?? null);
        $height = $this->toPositiveInt($payload['package_height_cm'] ?? $payload['height_cm'] ?? null);

        if ($length !== null || $width !== null || $height !== null) {
            return [
                'length_cm' => $length,
                'width_cm' => $width,
                'height_cm' => $height,
            ];
        }

        if (preg_match('/(\d{1,3})\s*[xX*]\s*(\d{1,3})(?:\s*[xX*]\s*(\d{1,3}))?\s*cm\b/iu', $text, $match) === 1) {
            return [
                'length_cm' => (int) $match[1],
                'width_cm' => (int) $match[2],
                'height_cm' => isset($match[3]) && $match[3] !== '' ? (int) $match[3] : null,
            ];
        }

        return [
            'length_cm' => null,
            'width_cm' => null,
            'height_cm' => null,
        ];
    }

    private function toPositiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $parsed = (int) round((float) $value);

        return $parsed > 0 ? $parsed : null;
    }

    /**
     * @param  array{length_cm: ?int, width_cm: ?int, height_cm: ?int}  $dimensions
     */
    private function needsSizeClarification(?string $description, ?float $weightKg, array $dimensions): bool
    {
        if ($description === null) {
            return false;
        }

        if ($this->isKnownSmallSafeItem($description)) {
            return false;
        }

        if ($weightKg !== null || $dimensions['length_cm'] !== null || $dimensions['width_cm'] !== null || $dimensions['height_cm'] !== null) {
            return false;
        }

        $normalized = strtolower($description);

        return mb_strlen($description) < 18 || preg_match('/\b(paket|barang|kiriman|box|kardus)\b/iu', $normalized) === 1;
    }

    private function isGenericPackageDescription(string $description): bool
    {
        $normalized = strtolower(trim($description));

        return in_array($normalized, ['paket', 'barang', 'kiriman', 'box', 'kardus'], true);
    }

    private function isKnownSmallSafeItem(?string $description): bool
    {
        if ($description === null) {
            return false;
        }

        return preg_match(
            '/\b(kacamata|dokumen|berkas|surat|ijazah|buku(?:\s+kecil)?|baju|pakaian|kunci|sabun|charger|kabel|earphone|headset|aksesoris|aksesori|alat tulis|pulpen|pensil|flashdisk|usb|kosmetik kecil)\b/iu',
            strtolower($description)
        ) === 1;
    }

    /**
     * @param  array<int, string>  $flags
     */
    private function resolveStatus(array $flags): string
    {
        foreach ($flags as $flag) {
            if (str_starts_with($flag, 'PROHIBITED_')) {
                return self::STATUS_PROHIBITED;
            }
        }

        $blockingFlags = array_values(array_filter(
            $flags,
            fn (string $flag): bool => $flag === 'GENERIC_DESCRIPTION'
        ));

        return $blockingFlags === [] ? self::STATUS_ALLOWED : self::STATUS_NEEDS_CLARIFICATION;
    }

    /**
     * @param  array{length_cm: ?int, width_cm: ?int, height_cm: ?int}  $dimensions
     * @param  array<int, string>  $flags
     */
    private function resolveSizeClass(?float $weightKg, array $dimensions, array $flags): string
    {
        $maxDimension = max(array_filter([
            $dimensions['length_cm'],
            $dimensions['width_cm'],
            $dimensions['height_cm'],
            0,
        ], fn (?int $value): bool => $value !== null));

        foreach ($flags as $flag) {
            if (str_starts_with($flag, 'OVERSIZE_')) {
                return 'OVERSIZE';
            }
        }

        if (($weightKg !== null && $weightKg > self::MAX_WEIGHT_KG) || $maxDimension > self::MAX_DIMENSION_CM) {
            return 'OVERSIZE';
        }

        if (($weightKg !== null && $weightKg > 5) || $maxDimension > 30) {
            return 'MEDIUM';
        }

        return 'SMALL';
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim((string) preg_replace('/\s+/', ' ', $value));

        return $normalized === '' ? null : $normalized;
    }
}
