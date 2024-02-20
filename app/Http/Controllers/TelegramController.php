<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use PhpOffice\PhpWord\TemplateProcessor;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;
use App\Models\User;
use App\Models\Memo;
use Illuminate\Http\Request;
use App\Services\TelegramBot;

class TelegramController extends Controller
{
    protected $telegramBotService;

    public function __construct(TelegramBot $telegramBotService)
    {
        $this->telegramBotService = $telegramBotService;
    }
    public function inbound(Request $request)
    {
        \Log::channel('null')->info('Skipping logging for inbound message');
        $chat_id = $request->message['from']['id'] ?? null;

        if ($request->message['text'] === '/start' || $request->message['text'] === '/help') {
            $chat_id = $request->message['from']['id'];

            $text = "หวัดดีจ้า! เรา MemoActivityBot ใหม่! 📝\n";
            $text .= "เรามีหลายฟังก์ชั่นที่คุณสามารถใช้งานได้:\n\n";
            $text .= "1. ข้อมูลส่วนตัว\n";
            $text .= "   /setinfo - ตั้งค่าข้อมูลส่วนตัว\n";
            $text .= "   /editinfo - แก้ไขข้อมูลส่วนตัว\n";
            $text .= "   /getinfo - เรียกดูข้อมูลส่วนตัว\n\n";
            $text .= "2. การแจ้งเตือนเพื่อจดบันทึกงานประจำวัน\n";
            $text .= "   /setreminder - ตั้งค่าเวลาแจ้งเตือน\n";
            $text .= "   /editreminder - แก้ไขเวลาแจ้งเตือน\n";
            $text .= "   /getreminder - เรียกดูเวลาแจ้งเตือน\n\n";
            $text .= "3. จดบันทึกงานประจำวัน\n";
            $text .= "   /memo - เริ่มจดบันทึกงานประจำวัน\n";
            $text .= "   /addmemo - เพิ่มบันทึกงานประจำวัน\n";
            $text .= "   /editmemo - แก้ไขบันทึกงานประจำวัน\n";
            $text .= "   /getmemo - เรียกดูบันทึกงานประจำวัน\n\n";
            $text .= "   /notetoday - เพิ่มหมายเหตุกรณีเป็นวันหยุด หรือวันลา\n\n";
            $text .= "   หากต้องการล้างบันทึก/หมายเหตุประจำวัน สามารถ\n";
            $text .= "   /resetmemo - ล้างบันทึกงานประจำวัน\n";
            $text .= "   /resetnotetoday - ล้างหมายเหตุประจำวัน\n\n";

            $text .= "   /weeklysummary - สรุปงานประจำสัปดาห์\n";
            $text .= "   /generateDoc - สร้างเอกสารสรุปงานประจำสัปดาห์\n";

            $result = app('telegram_bot')->sendMessage($chat_id, $text);

            return response()->json($result, 200);
        }
    }
}