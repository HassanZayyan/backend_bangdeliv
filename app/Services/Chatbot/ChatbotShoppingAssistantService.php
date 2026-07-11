<?php

namespace App\Services\Chatbot;

use App\Models\Menu;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Address\ChatbotAddressReadinessService;
use App\Support\GeoDistance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ChatbotShoppingAssistantService
{
    private const MENU_PAGE_SIZE = 8;

    private const ACTIVE_RECOMMENDATION_LIMIT = 3;

    private const NEARBY_RESTAURANT_LIMIT = 3;

    private const NEARBY_MENU_LIMIT = 2;

    /**
     * @var array<int, string>
     */
    private const INFORMATIONAL_COMMANDS = [
        'show_menu',
        'menu_next',
        'menu_previous',
        'search_menu',
        'recommend_food',
    ];

    public function __construct(
        private readonly ChatbotDraftStore $draftStore,
        private readonly ChatbotAddressReadinessService $addressReadinessService,
    ) {}

    public function supports(string $command): bool
    {
        return in_array($command, self::INFORMATIONAL_COMMANDS, true);
    }

    /**
     * @param  array<string, mixed>|null  $nluPayload
     * @param  array<string, mixed>  $basePayload
     */
    public function assistantText(
        User $user,
        string $sessionId,
        string $message,
        string $command,
        ?array $nluPayload,
        array $basePayload,
    ): string {
        if ($command === 'recommend_food') {
            return $this->recommendFood($user, $sessionId, $message, $nluPayload, $basePayload);
        }

        return $this->browseMenu($user, $sessionId, $message, $command, $nluPayload, $basePayload);
    }

    /**
     * @param  array<string, mixed>|null  $nluPayload
     * @param  array<string, mixed>  $basePayload
     */
    private function browseMenu(
        User $user,
        string $sessionId,
        string $message,
        string $command,
        ?array $nluPayload,
        array $basePayload,
    ): string {
        $stops = $this->draftStops($basePayload);
        $state = $this->draftStore->shoppingAssistantState($user, $sessionId);
        $search = $command === 'search_menu'
            ? $this->extractMenuSearch($message, $nluPayload)
            : null;
        $selection = $this->resolveSelection($stops, $message, $nluPayload, $command, $state);

        if (($selection['status'] ?? null) !== 'selected') {
            $this->savePendingSelectionState($user, $sessionId, $stops, $command, $search);

            return $this->selectionFailureText($selection, $stops, 'melihat menu');
        }

        $selected = $selection['stop'];
        $restaurant = $selected['restaurant'];
        if (! $restaurant instanceof Restaurant) {
            $this->draftStore->forgetShoppingAssistantState($user, $sessionId);

            return "Menu {$selected['name']} belum tersedia di katalog BangDeliv. Tulis nama item dan jumlahnya secara manual untuk tempat ini.";
        }

        if ($command === 'search_menu' && $search === null) {
            return "Sebutkan menu yang ingin dicari di {$restaurant->name}, misalnya: cari menu ayam.";
        }

        if (in_array($command, ['menu_next', 'menu_previous'], true)) {
            $search = $this->normalizeOptionalString($state['menu_search'] ?? null);
        }

        $sameBrowseTarget = (int) ($state['restaurant_id'] ?? 0) === (int) $restaurant->id
            && $this->normalizeText((string) ($state['menu_search'] ?? '')) === $this->normalizeText((string) ($search ?? ''));
        $currentOffset = $sameBrowseTarget ? max(0, (int) ($state['menu_offset'] ?? 0)) : 0;

        if ($command === 'menu_next') {
            if (! $sameBrowseTarget) {
                return 'Belum ada daftar menu yang sedang dibuka. Ketik "tampilkan menu" terlebih dahulu.';
            }
            $offset = $currentOffset + self::MENU_PAGE_SIZE;
        } elseif ($command === 'menu_previous') {
            if (! $sameBrowseTarget) {
                return 'Belum ada daftar menu yang sedang dibuka. Ketik "tampilkan menu" terlebih dahulu.';
            }
            $offset = max(0, $currentOffset - self::MENU_PAGE_SIZE);
        } else {
            $offset = 0;
        }

        $query = $this->availableMenuQuery($restaurant, $search);
        $total = (clone $query)->count();
        if ($total === 0) {
            $this->saveBrowseState($user, $sessionId, $selected, 0, $search, 'menu');

            return $search === null
                ? "Menu tersedia untuk {$restaurant->name} belum ada. Kamu tetap bisa menuliskan item secara manual."
                : "Saya belum menemukan menu yang cocok dengan \"{$search}\" di {$restaurant->name}. Mau melihat semua menunya?";
        }

        if ($offset >= $total) {
            return "Itu sudah halaman menu terakhir {$restaurant->name}. Ketik \"menu sebelumnya\" untuk kembali.";
        }

        $menus = $query->skip($offset)->take(self::MENU_PAGE_SIZE)->get();
        $this->saveBrowseState($user, $sessionId, $selected, $offset, $search, 'menu');

        $name = $this->customerName($user);
        $lines = [$search === null
            ? "Baik {$name}, ini menu {$restaurant->name}:"
            : "Baik {$name}, ini hasil pencarian \"{$search}\" di {$restaurant->name}:"];

        foreach ($menus as $index => $menu) {
            $lines[] = ($offset + $index + 1).'. '.$this->menuDisplay($menu);
        }

        $lines[] = '';
        $hasPrevious = $offset > 0;
        $hasNext = $offset + $menus->count() < $total;
        if ($hasNext && $hasPrevious) {
            $lines[] = 'Ketik "menu berikutnya" atau "menu sebelumnya" untuk berpindah halaman.';
        } elseif ($hasNext) {
            $lines[] = 'Masih ada menu lainnya. Ketik "menu berikutnya" untuk melanjutkan.';
        } elseif ($hasPrevious) {
            $lines[] = 'Ini halaman terakhir. Ketik "menu sebelumnya" untuk kembali.';
        } else {
            $lines[] = 'Sebutkan nama menu dan jumlahnya jika ingin menambahkannya ke draft.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>|null  $nluPayload
     * @param  array<string, mixed>  $basePayload
     */
    private function recommendFood(
        User $user,
        string $sessionId,
        string $message,
        ?array $nluPayload,
        array $basePayload,
    ): string {
        $stops = $this->draftStops($basePayload);
        if ($stops !== []) {
            $state = $this->draftStore->shoppingAssistantState($user, $sessionId);
            $selection = $this->resolveSelection($stops, $message, $nluPayload, 'recommend_food', $state);
            if (($selection['status'] ?? null) !== 'selected') {
                $this->savePendingSelectionState($user, $sessionId, $stops, 'recommend_food', null);

                return $this->selectionFailureText($selection, $stops, 'memberi rekomendasi');
            }

            $selected = $selection['stop'];
            $restaurant = $selected['restaurant'];
            if (! $restaurant instanceof Restaurant) {
                return "Saya belum memiliki data menu {$selected['name']}. Tulis jenis makanan yang kamu inginkan, lalu masukkan itemnya secara manual.";
            }

            $menus = $restaurant->menus()
                ->where('is_available', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit(self::ACTIVE_RECOMMENDATION_LIMIT)
                ->get();

            if ($menus->isEmpty()) {
                return "Belum ada menu tersedia di {$restaurant->name}. Kamu tetap bisa menuliskan item yang ingin dipesan secara manual.";
            }

            $this->saveBrowseState($user, $sessionId, $selected, 0, null, 'recommendation');
            $lines = ["Untuk {$restaurant->name}, saya sarankan pilihan berikut:"];
            foreach ($menus as $index => $menu) {
                $lines[] = ($index + 1).'. '.$this->menuDisplay($menu);
            }
            $lines[] = '';
            $lines[] = 'Pilih salah satu dengan menuliskan nama menu dan jumlahnya.';

            return implode("\n", $lines);
        }

        $location = $this->recommendationLocation($user, $basePayload);
        if ($location === null) {
            return 'Saya perlu lokasi antar untuk mencari pilihan terdekat. Lengkapi alamatmu terlebih dahulu, ya.';
        }

        $restaurants = Restaurant::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereHas('menus', fn (Builder $query): Builder => $query->where('is_available', true))
            ->with(['menus' => fn ($query) => $query
                ->where('is_available', true)
                ->orderBy('sort_order')
                ->orderBy('id')])
            ->get()
            ->filter(fn (Restaurant $restaurant): bool => GeoDistance::isValidCoordinatePair(
                $restaurant->latitude,
                $restaurant->longitude,
            ))
            ->map(function (Restaurant $restaurant) use ($location): array {
                return [
                    'restaurant' => $restaurant,
                    'distance_meters' => GeoDistance::meters(
                        $location['latitude'],
                        $location['longitude'],
                        (float) $restaurant->latitude,
                        (float) $restaurant->longitude,
                    ),
                ];
            })
            ->sortBy('distance_meters')
            ->take(self::NEARBY_RESTAURANT_LIMIT)
            ->values();

        if ($restaurants->isEmpty()) {
            return 'Saya belum menemukan restoran dengan menu tersedia di sekitar alamatmu. Kamu bisa memilih tempat lewat peta.';
        }

        $this->draftStore->saveShoppingAssistantState($user, $sessionId, [
            'mode' => 'nearby_recommendation',
            'restaurant_id' => null,
            'restaurant_name' => null,
            'menu_offset' => 0,
            'menu_search' => null,
        ]);

        $lines = ['Berikut pilihan dari restoran terdekat dengan alamat antarmu:'];
        foreach ($restaurants as $index => $candidate) {
            /** @var Restaurant $restaurant */
            $restaurant = $candidate['restaurant'];
            $distanceKm = round(((float) $candidate['distance_meters']) / 1000, 2);
            $lines[] = '';
            $lines[] = ($index + 1).'. '.$restaurant->name.' (sekitar '.number_format($distanceKm, 2, ',', '.').' km)';
            foreach ($restaurant->menus->take(self::NEARBY_MENU_LIMIT) as $menu) {
                $lines[] = '- '.$this->menuDisplay($menu);
            }
        }
        $lines[] = '';
        $lines[] = 'Sebutkan nama restoran yang ingin dipakai, lalu tulis menu dan jumlah pesanannya.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $stops
     * @param  array<string, mixed>|null  $nluPayload
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function resolveSelection(
        array $stops,
        string $message,
        ?array $nluPayload,
        string $command,
        array $state,
    ): array {
        if ($stops === []) {
            return ['status' => 'empty'];
        }

        if (in_array($command, ['menu_next', 'menu_previous'], true)) {
            $stateRestaurantId = (int) ($state['restaurant_id'] ?? 0);
            $stateStopIndex = isset($state['stop_index']) ? (int) $state['stop_index'] : null;
            foreach ($stops as $stop) {
                if (($stateRestaurantId > 0 && (int) ($stop['restaurant_id'] ?? 0) === $stateRestaurantId)
                    || ($stateRestaurantId === 0 && $stateStopIndex === (int) $stop['index'])) {
                    return ['status' => 'selected', 'stop' => $stop];
                }
            }

            return ['status' => 'no_browse_state'];
        }

        $ordinalIndex = $this->extractOrdinalIndex($message);
        $ordinalStop = $ordinalIndex !== null ? ($stops[$ordinalIndex] ?? null) : null;
        if ($ordinalIndex !== null && $ordinalStop === null) {
            return ['status' => 'invalid_ordinal'];
        }

        $mentionedStops = array_values(array_filter($stops, fn (array $stop): bool => str_contains(
            $this->normalizeText($message),
            $this->normalizeText((string) $stop['name']),
        )));
        if (count($mentionedStops) > 1) {
            return ['status' => 'ambiguous'];
        }

        $reference = $this->restaurantReference($message, $nluPayload, $command, $stops);
        $nameResult = $reference === null ? null : $this->matchStopByName($stops, $reference);

        if ($ordinalStop !== null && is_array($nameResult) && ($nameResult['status'] ?? null) === 'selected') {
            if ((int) $nameResult['stop']['index'] !== (int) $ordinalStop['index']) {
                return ['status' => 'conflict'];
            }
        }

        if ($ordinalStop !== null) {
            return ['status' => 'selected', 'stop' => $ordinalStop];
        }
        if (is_array($nameResult)) {
            return $nameResult;
        }
        if (count($stops) === 1) {
            return ['status' => 'selected', 'stop' => $stops[0]];
        }

        return ['status' => 'needs_selection'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $stops
     * @return array<string, mixed>
     */
    private function matchStopByName(array $stops, string $reference): array
    {
        $normalizedReference = $this->normalizeText($reference);
        if ($normalizedReference === '') {
            return ['status' => 'not_found'];
        }

        $exact = array_values(array_filter(
            $stops,
            fn (array $stop): bool => $this->normalizeText((string) $stop['name']) === $normalizedReference,
        ));
        if (count($exact) === 1) {
            return ['status' => 'selected', 'stop' => $exact[0]];
        }

        $partial = array_values(array_filter($stops, function (array $stop) use ($normalizedReference): bool {
            $normalizedName = $this->normalizeText((string) $stop['name']);

            return str_contains($normalizedName, $normalizedReference)
                || str_contains($normalizedReference, $normalizedName);
        }));
        if (count($partial) === 1) {
            return ['status' => 'selected', 'stop' => $partial[0]];
        }
        if (count($partial) > 1) {
            return ['status' => 'ambiguous'];
        }

        if (mb_strlen($normalizedReference) < 5) {
            return ['status' => 'not_found'];
        }

        $scores = collect($stops)
            ->map(function (array $stop) use ($normalizedReference): array {
                $name = $this->normalizeText((string) $stop['name']);
                $maxLength = max(strlen($name), strlen($normalizedReference), 1);
                $similarity = 1 - (levenshtein($name, $normalizedReference) / $maxLength);

                return ['stop' => $stop, 'score' => $similarity];
            })
            ->sortByDesc('score')
            ->values();
        $best = $scores->first();
        $second = $scores->get(1);
        if (is_array($best)
            && (float) $best['score'] >= 0.85
            && (! is_array($second) || ((float) $best['score'] - (float) $second['score']) >= 0.10)) {
            return ['status' => 'selected', 'stop' => $best['stop']];
        }

        return ['status' => 'not_found'];
    }

    /**
     * @param  array<string, mixed>  $selection
     * @param  array<int, array<string, mixed>>  $stops
     */
    private function selectionFailureText(array $selection, array $stops, string $purpose): string
    {
        $status = (string) ($selection['status'] ?? 'not_found');
        if ($status === 'empty') {
            return 'Belum ada restoran di draft Nitip. Pilih tempat terlebih dahulu atau minta rekomendasi makanan terdekat.';
        }
        if ($status === 'no_browse_state') {
            return 'Belum ada daftar menu yang sedang dibuka. Ketik "tampilkan menu" terlebih dahulu.';
        }

        $intro = match ($status) {
            'ambiguous' => 'Nama restoran itu cocok dengan lebih dari satu pilihan.',
            'conflict' => 'Nomor dan nama restoran yang disebut tidak menunjuk pilihan yang sama.',
            'invalid_ordinal' => 'Nomor restoran itu tidak tersedia di draft.',
            'not_found' => 'Restoran yang disebut belum ada di draft.',
            default => count($stops) > 1
                ? 'Ada beberapa restoran di draft.'
                : "Saya belum bisa menentukan restoran untuk {$purpose}.",
        };

        return implode("\n", [
            $intro.' Pilih salah satu:',
            ...$this->stopChoiceLines($stops),
            '',
            'Balas dengan nomor urut atau nama restorannya.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $basePayload
     * @return array<int, array<string, mixed>>
     */
    private function draftStops(array $basePayload): array
    {
        $payloadStops = data_get($basePayload, 'shopping.stops', []);
        if (! is_array($payloadStops)) {
            return [];
        }

        $restaurantIds = collect($payloadStops)
            ->map(fn ($stop): int => is_array($stop) && is_numeric(data_get($stop, 'merchant.id'))
                ? (int) data_get($stop, 'merchant.id')
                : 0)
            ->filter()
            ->unique()
            ->values();
        $restaurants = Restaurant::query()
            ->whereIn('id', $restaurantIds)
            ->get()
            ->keyBy('id');

        $stops = [];
        foreach ($payloadStops as $index => $stop) {
            if (! is_array($stop)) {
                continue;
            }
            $name = trim((string) data_get($stop, 'merchant.name', ''));
            if ($name === '') {
                continue;
            }
            $restaurantId = is_numeric(data_get($stop, 'merchant.id'))
                ? (int) data_get($stop, 'merchant.id')
                : null;
            $stops[] = [
                'index' => (int) $index,
                'name' => $name,
                'restaurant_id' => $restaurantId,
                'restaurant' => $restaurantId !== null ? $restaurants->get($restaurantId) : null,
            ];
        }

        return $stops;
    }

    /**
     * @param  array<int, array<string, mixed>>  $stops
     * @param  array<string, mixed>|null  $nluPayload
     */
    private function restaurantReference(string $message, ?array $nluPayload, string $command, array $stops): ?string
    {
        $nluReference = $this->normalizeOptionalString($nluPayload['merchant'] ?? $nluPayload['resto'] ?? null);
        if ($nluReference !== null) {
            return $nluReference;
        }

        $normalizedMessage = $this->normalizeText($message);
        $mentionedNames = array_values(array_filter(
            $stops,
            fn (array $stop): bool => str_contains($normalizedMessage, $this->normalizeText((string) $stop['name'])),
        ));
        if (count($mentionedNames) === 1) {
            return (string) $mentionedNames[0]['name'];
        }
        if (count($mentionedNames) > 1) {
            return $message;
        }

        if (preg_match('/\b(?:di|dari)\s+(.+)$/iu', $message, $match) === 1) {
            return $this->normalizeOptionalString($match[1]);
        }

        if ($command === 'search_menu') {
            return null;
        }

        $reference = preg_replace(
            '/\b(?:tolong|dong|ya|saya|aku|mau|ingin|coba|tampilkan|lihat|cek|buka|bukakan|daftar|menu|menunya|rekomendasi|rekomendasikan|sarankan|makanan|makan|apa|enaknya|restoran mana|resto mana|pertama|kesatu|kedua|ketiga)\b/iu',
            ' ',
            $message,
        );
        $reference = preg_replace('/\b(?:nomor|no|resto|restoran|restaurant|tempat|yang)\s*[1-3]\b/iu', ' ', (string) $reference);
        $reference = trim((string) preg_replace('/\s+/', ' ', (string) $reference));
        $reference = preg_replace('/^yang\s+/iu', '', $reference);

        return $this->normalizeOptionalString($reference);
    }

    private function extractOrdinalIndex(string $message): ?int
    {
        $normalized = $this->normalizeText($message);
        $wordIndexes = [
            'pertama' => 0,
            'kesatu' => 0,
            'kedua' => 1,
            'ketiga' => 2,
        ];
        foreach ($wordIndexes as $word => $index) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/u', $normalized) === 1) {
                return $index;
            }
        }

        if (preg_match('/^(?:yang\s+)?([1-3])$/u', $normalized, $match) === 1
            || preg_match('/\b(?:nomor|no|resto|restoran|restaurant|tempat|yang)\s*([1-3])\b/u', $normalized, $match) === 1) {
            return ((int) $match[1]) - 1;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $nluPayload
     */
    private function extractMenuSearch(string $message, ?array $nluPayload): ?string
    {
        $fromNlu = $this->normalizeOptionalString($nluPayload['menu_search'] ?? null);
        if ($fromNlu !== null) {
            return $fromNlu;
        }

        $patterns = [
            '/\b(?:cari|carikan)\s+(?:menu\s+)?(.+?)(?:\s+(?:di|dari)\s+.+)?$/iu',
            '/\b(?:ada|punya)\s+(?:menu\s+)?(.+?)(?:\s+(?:di|dari)\s+.+)?(?:\s+(?:nggak|ga|tidak))?\??$/iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, trim($message), $match) === 1) {
                $search = trim((string) $match[1]);
                $search = preg_replace('/\s+(?:nggak|ga|tidak)$/iu', '', $search);

                return $this->normalizeOptionalString($search);
            }
        }

        if (preg_match('/\bminuman(?:nya)?\b/iu', $message) === 1) {
            return 'minuman';
        }

        return null;
    }

    private function availableMenuQuery(Restaurant $restaurant, ?string $search): HasMany
    {
        $query = $restaurant->menus()
            ->where('is_available', true)
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($search === null) {
            return $query;
        }

        if (in_array($this->normalizeText($search), ['minum', 'minuman', 'minumannya'], true)) {
            $beverageTokens = ['es', 'teh', 'kopi', 'jus', 'susu', 'coklat', 'air', 'lemon', 'soda', 'matcha'];

            return $query->where(function (Builder $builder) use ($beverageTokens): void {
                foreach ($beverageTokens as $index => $token) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $builder->{$method}('name', 'like', '%'.$token.'%');
                }
            });
        }

        return $query->where('name', 'like', '%'.$search.'%');
    }

    /**
     * @param  array<string, mixed>  $basePayload
     * @return array{latitude: float, longitude: float}|null
     */
    private function recommendationLocation(User $user, array $basePayload): ?array
    {
        $latitude = data_get($basePayload, 'shopping.delivery.latitude');
        $longitude = data_get($basePayload, 'shopping.delivery.longitude');
        if (GeoDistance::isValidCoordinatePair($latitude, $longitude)) {
            return ['latitude' => (float) $latitude, 'longitude' => (float) $longitude];
        }

        $address = $this->addressReadinessService->resolveDefaultUsableAddress($user);
        $location = $this->addressReadinessService->toLocationPayload($address);

        return $location === null ? null : [
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
        ];
    }

    /**
     * @param  array<string, mixed>  $selected
     */
    private function saveBrowseState(
        User $user,
        string $sessionId,
        array $selected,
        int $offset,
        ?string $search,
        string $mode,
    ): void {
        $this->draftStore->saveShoppingAssistantState($user, $sessionId, [
            'mode' => $mode,
            'restaurant_id' => $selected['restaurant_id'],
            'restaurant_name' => $selected['name'],
            'stop_index' => $selected['index'],
            'menu_offset' => $offset,
            'menu_search' => $search,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $stops
     */
    private function savePendingSelectionState(
        User $user,
        string $sessionId,
        array $stops,
        string $command,
        ?string $search,
    ): void {
        if ($stops === []) {
            return;
        }

        $this->draftStore->saveShoppingAssistantState($user, $sessionId, [
            'mode' => 'awaiting_restaurant',
            'pending_command' => $command,
            'menu_search' => $search,
            'restaurant_choices' => array_map(fn (array $stop): array => [
                'index' => $stop['index'],
                'restaurant_id' => $stop['restaurant_id'],
                'name' => $stop['name'],
            ], $stops),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $stops
     * @return array<int, string>
     */
    private function stopChoiceLines(array $stops): array
    {
        return array_map(
            fn (array $stop, int $index): string => ($index + 1).'. '.(string) $stop['name'],
            $stops,
            array_keys($stops),
        );
    }

    private function menuDisplay(Model $menu): string
    {
        if (! $menu instanceof Menu) {
            throw new \LogicException('Relasi menu restoran mengembalikan model yang tidak valid.');
        }

        $price = is_numeric($menu->price) ? (float) $menu->price : 0;
        $priceText = $price > 0
            ? 'referensi Rp '.number_format($price, 0, ',', '.')
            : 'harga belum tersedia';

        return $menu->name.' ('.$priceText.')';
    }

    private function customerName(User $user): string
    {
        $name = trim((string) $user->name);

        return $name === '' ? 'Kak' : $name;
    }

    private function normalizeText(string $value): string
    {
        $value = Str::ascii(mb_strtolower($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', (string) $value));
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
