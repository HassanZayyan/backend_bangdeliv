<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AdminNotificationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(public array $summary) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('admin.notifications'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'admin.notifications.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        // Channel 'admin.notifications' tidak terotorisasi, sehingga siapa pun
        // yang memegang REVERB_APP_KEY (nilai publik yang ikut terkirim di
        // setiap klien) dapat menyimaknya. Karena itu payload sengaja hanya
        // berupa sinyal "ada perubahan" tanpa data apa pun: nama driver, pelat
        // kendaraan, dan nomor order TIDAK boleh keluar lewat jalur ini.
        //
        // Panel admin memang tidak membaca payload ini. Lihat
        // resources/views/layouts/admin.blade.php - refreshAdminNotifications()
        // mengabaikan isi event lalu mengambil ulang datanya dari endpoint
        // admin.notifications.pending yang terotentikasi.
        return [
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
