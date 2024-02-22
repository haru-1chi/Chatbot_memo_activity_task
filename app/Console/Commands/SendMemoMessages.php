<?php

namespace App\Console\Commands;

use App\Services\TelegramBot;
use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\User;

class SendMemoMessages extends Command
{
    protected $telegramBot;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send:memo-messages';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send memo messages to users at their specified memo times';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(TelegramBot $telegramBot)
    {
        parent::__construct();
        $this->telegramBot = $telegramBot;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        info('called when memo_time');
        $currentTime = Carbon::now();
        if ($currentTime->isWeekday()) {
            $users = User::whereNotNull('telegram_chat_id')->get();
            foreach ($users as $user) {
                if ($user->memo_time) {
                    $memoTime = Carbon::createFromFormat('H:i:s', $user->memo_time)->format('H:i');
                    $currentTimeFormatted = $currentTime->format('H:i');
                    if ($currentTimeFormatted === $memoTime) {
                        $text = "วันนี้อย่าลืมจดบันทึกงานประจำวันด้วยนะ\n";
                        $text .= "กรุณาพิมพ์ /memo เพื่อเริ่มจดบันทึก\n\n";
                        $text .= "หรือหากวันนี้ลาหยุด หรือเป็นวันหยุดราชการ ให้พิมพ์ /notetoday เพื่อเพิ่มหมายเหตุวันนี้\n";
                        $this->sendMessageToUser($user->telegram_chat_id, $text);
                    }
                } elseif (!$user->memo_time && $currentTime->format('H:i') === '12:00') {
                    $text = "นี่เป็นข้อความแจ้งเตือนให้จดบันทึกประจำวันเบื้องต้น\n";
                    $text .= "กรุณา /setreminder เพื่อตั้งค่าการแจ้งเตือน\n\n";
                    $text .= "อย่าลืมตั้งค่าเวลาแจ้งเตือนบันทึกประจำวันด้วยนะ\n";
                    $this->sendMessageToUser($user->telegram_chat_id, $text);
                }
            }
        }

        return 0;
    }

    /**
     * Send message to user using Telegram Bot service.
     *
     * @param int $chatId
     * @param string $message
     * @return void
     */
    private function sendMessageToUser($chatId, $message)
    {
        $this->telegramBot->sendMessage($chatId, $message);
    }
}
