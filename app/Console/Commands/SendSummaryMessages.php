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
        $currentTime = Carbon::now();
        if ($currentTime->isWeekday()) {
            $users = User::whereNotNull('telegram_chat_id')->get();
            foreach ($users as $user) {
                if ($user->summary_time) {
                    $summaryTime = Carbon::createFromFormat('H:i:s', $user->summary_time)->format('H:i');
                    $currentTimeFormatted = $currentTime->format('H:i');
                    if ($currentTimeFormatted === $summaryTime) {

                        $userMemo = $this->getUserMemo($user->telegram_chat_id);
                        if ($userMemo['memo']) {
                            $memoArray = explode(', ', $userMemo['memo']);
                            $formattedMemo = [];
                            foreach ($memoArray as $key => $memo) {
                                $formattedMemo[] = ($key + 1) . ". " . $memo;
                            }
                            $text = "สรุปงานที่ได้ทำในวันนี้:\n" . implode("\n", $formattedMemo);
                            if ($userMemo['note_today']) {
                                $text .= "\n\nหมายเหตุประจำวัน:\n{$userMemo['note_today']}";
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

                        } elseif ($userMemo['note_today'] && empty($userMemo['memo'])) {
                            $text = "สรุปงานที่ได้ทำในวันนี้:\nไม่มีบันทึกงานประจำวัน";
                            $text .= "หมายเหตุประจำวัน:\n{$userMemo['note_today']}";
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
                        } elseif (empty($userMemo['memo'] && $userMemo['note_today'])) {
                            $text = "สรุปงานที่ได้ทำในวันนี้:\n";
                            $text = "คุณยังไม่ได้จดบันทึกงานประจำวัน!\n";
                            $text .= "กรุณา /memo เพื่อเริ่มจดบันทึกประจำวัน\n\n";
                            $text .= "หรือหากวันนี้ลาหยุด หรือเป็นวันหยุดราชการ ให้พิมพ์ /notetoday เพื่อเพิ่มหมายเหตุวันนี้\n";
                            $text .= "กรุณาจดบันทึกก่อนเวลา 23:59 น. ของวันนี้ด้วยนะ";
                            $this->sendMessageToUser($user->telegram_chat_id, $text);
                        }
                    }
                } else {
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
     * @param int $chatId
     * @param string $message
     * @return void
     */
    private function sendMessageToUser($chatId, $message)
    {
        $this->telegramBot->sendMessage($message, $chatId);
    }

    public function getUserMemo($telegram_chat_id)
    {
        $currentDate = now()->toDateString();
        $userMemo = Memo::where('user_id', $telegram_chat_id)->where('memo_date', $currentDate)->first();
        return $userMemo;
    }
}
