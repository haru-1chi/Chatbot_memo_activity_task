<?php

namespace App\Console\Commands;

use App\Services\TelegramBot;
use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Memo;

class SendSummaryMessages extends Command
{
    protected $telegramBot;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send:summary-messages';

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
        info('called when summary_time');
        $current_time = Carbon::now();
        if ($current_time->isWeekday()) {
            $users = User::whereNotNull('telegram_chat_id')->get();
            foreach ($users as $user) {
                if ($user->summary_time) {
                    $summary_time = Carbon::createFromFormat('H:i:s', $user->summary_time)->format('H:i');
                    $current_time_formatted = $current_time->format('H:i');
                    if ($current_time_formatted === $summary_time) {
                        $user_memo = $this->getUserMemo($user->telegram_chat_id);
                        if (!$user_memo || (!$user_memo['memo'] && !$user_memo['note_today'])) {
                            $text = "สรุปงานที่ได้ทำในวันนี้:\n";
                            $text = "คุณยังไม่ได้จดบันทึกงานประจำวัน!\n";
                            $text .= "กรุณา /memo เพื่อเริ่มจดบันทึกประจำวัน\n\n";
                            $text .= "หรือหากวันนี้ลาหยุด หรือเป็นวันหยุดราชการ ให้พิมพ์ /notetoday เพื่อเพิ่มหมายเหตุวันนี้\n";
                            $text .= "กรุณาจดบันทึกก่อนเวลา 23:59 น. ของวันนี้ด้วยนะ";
                            $this->sendMessageToUser($user->telegram_chat_id, $text);
                        } elseif ($user_memo['memo']){
                            $memo_array = explode(', ', $user_memo['memo']);
                            $formatted_memo = [];
                            foreach ($memo_array as $key => $memo) {
                                $formatted_memo[] = ($key + 1) . ". " . $memo;
                            }
                            $text = "สรุปงานที่ได้ทำในวันนี้:\n" . implode("\n", $formatted_memo);
                            if ($user_memo['note_today']) {
                                $text .= "\n\nหมายเหตุประจำวัน:\n{$user_memo['note_today']}";
                            }
                            $text .= "\n\nหรือคุณต้องการ\n";
                            $text .= "   /addmemo - เพิ่มบันทึกงานประจำวัน\n";
                            $text .= "   /editmemo - แก้ไขบันทึกงานประจำวัน\n";
                            $text .= "   /getmemo - เรียกดูบันทึกงานประจำวัน\n\n";
                            $text .= "   /notetoday - เพิ่มหมายเหตุกรณีเป็นวันหยุด หรือวันลา\n\n";
                            $text .= "   หากต้องการล้างบันทึก/หมายเหตุประจำวัน สามารถ\n";
                            $text .= "   /resetmemo - ล้างบันทึกงานประจำวัน\n";
                            $text .= "   /resetnotetoday - ล้างหมายเหตุประจำวัน\n\n";

                            $this->sendMessageToUser($user->telegram_chat_id, $text);

                        } elseif ($user_memo['note_today'] && empty($user_memo['memo'])) {
                            $text = "สรุปงานที่ได้ทำในวันนี้:\nไม่มีบันทึกงานประจำวัน";
                            $text .= "หมายเหตุประจำวัน:\n{$user_memo['note_today']}";
                            $text .= "\n\nหรือคุณต้องการ\n";
                            $text .= "   /memo - เริ่มจดบันทึกงานประจำวัน\n";
                            $text .= "   /addmemo - เพิ่มบันทึกงานประจำวัน\n";
                            $text .= "   /editmemo - แก้ไขบันทึกงานประจำวัน\n";
                            $text .= "   /getmemo - เรียกดูบันทึกงานประจำวัน\n\n";
                            $text .= "   /notetoday - เพิ่มหมายเหตุกรณีเป็นวันหยุด หรือวันลา\n\n";
                            $text .= "   หากต้องการล้างบันทึก/หมายเหตุประจำวัน สามารถ\n";
                            $text .= "   /resetmemo - ล้างบันทึกงานประจำวัน\n";
                            $text .= "   /resetnotetoday - ล้างหมายเหตุประจำวัน\n\n";
                            $this->sendMessageToUser($user->telegram_chat_id, $text);
                        }
                    }
                } elseif (!$user->memo_time && $current_time->format('H:i') === '18:00') {
                    $text = "นี่เป็นข้อความแจ้งเตือนสรุปงานประจำวันเบื้องต้น\n";
                    $text .= "กรุณา /setreminder เพื่อตั้งค่าการแจ้งเตือน\n\n";
                    $text .= "อย่าลืมตั้งค่าเวลาแจ้งเตือนสรุปงานประจำวันด้วยนะ\n";
                    $this->sendMessageToUser($user->telegram_chat_id, $text);
                }
            }
        }
        return 0;
    }

    /**
     * Send message to user using Telegram Bot service.
     *
     * @param int $chat_id
     * @param string $message
     * @return void
     */
    private function sendMessageToUser($chat_id, $message)
    {
        $this->telegramBot->sendMessage($chat_id, $message);
    }

    public function getUserMemo($telegram_chat_id)
    {
        $current_date = now()->toDateString();
        $user_memo = Memo::where('user_id', $telegram_chat_id)->where('memo_date', $current_date)->first();
        return $user_memo;
    }
}
