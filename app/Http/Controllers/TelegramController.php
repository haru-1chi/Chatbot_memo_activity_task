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
        \Log::info($request->all());
        $chat_id = $request->message['from']['id'] ?? null;
        $reply_to_message = $request->message['message_id'] ?? null;
        \Log::info("chat_id: {$chat_id}");
        \Log::info("reply_to_message: {$reply_to_message}");

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

            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

            return response()->json($result, 200);
        }
        //info
        if (strpos($request->message['text'], '/setinfo') !== false) {
            $userInfo = User::where('telegram_chat_id', $chat_id)->first();
            if ($userInfo) {
                $text = "คุณได้ตั้งค่าข้อมูลส่วนตัวของคุณไปแล้ว!\n";
                $text .= "ถ้าคุณต้องการแก้ไขข้อมูลให้ใช้คำสั่ง /editinfo";

                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

                return response()->json($result, 200);
            }

            $text = "กรุณากรอกข้อมูลตามนี้:\n";
            $text .= "1. ชื่อ-นามสกุล\n";
            $text .= "2. รหัสนิสิต\n";
            $text .= "3. เบอร์โทรศัพท์\n";
            $text .= "4. สาขาวิชา\n";
            $text .= "5. สถานประกอบการ\n";
            $text .= "โปรดส่งข้อมูลในรูปแบบดังกล่าว\n";

            cache()->put("chat_id_{$chat_id}_user_info", true, now()->addMinutes(60));

            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

            return response()->json($result, 200);
        }

        if (cache()->has("chat_id_{$chat_id}_user_info")) {
            return $this->confirmUserInfo($request);
        }
    }

    //setinfo
    public function confirmUserInfo(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $reply_to_message = $request->message['message_id'] ?? null;
        $step = cache()->get("chat_id_{$chat_id}_user_info");
        if ($step) {
            $userInformationLines = explode("\n", $request->message['text']);

            if (count($userInformationLines) >= 5) {
                $name = trim($userInformationLines[0]);
                $student_id = trim($userInformationLines[1]);
                $phone_number = trim(preg_replace('/\D/', '', $userInformationLines[2]));
                $branch = isset($userInformationLines[3]) ? trim($userInformationLines[3]) : '';
                $company = isset($userInformationLines[4]) ? trim($userInformationLines[4]) : '';

                $text = "ข้อมูลที่คุณกรอกมีดังนี้:\n";
                $text .= "ชื่อ-นามสกุล: $name\n";
                $text .= "รหัสนิสิต: $student_id\n";
                $text .= "เบอร์โทรศัพท์: $phone_number\n";
                $text .= "สาขาวิชา: $branch\n";
                $text .= "สถานประกอบการ: $company\n";
                $text .= "ถูกต้องมั้ยคะ? (กรุณาตอบ yes หรือ /cancel)";

                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

                cache()->put("chat_id_{$chat_id}_user_info", compact('name', 'student_id', 'phone_number', 'branch', 'company'), now()->addMinutes(10));
                cache()->put("chat_id_{$chat_id}_user_info_confirm", true, now()->addMinutes(10));
                return response()->json($result, 200);
            }
            //   else {
            //     $text = "กรุณากรอกข้อมูลให้ครบถ้วนตามรูปแบบที่กำหนด";
            //     $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
            //     return response()->json($result, 200);
            // }

            if (cache()->has("chat_id_{$chat_id}_user_info_confirm")) {
                cache()->forget("chat_id_{$chat_id}_user_info_confirm");
                \Log::info('Calling confirmUserInfo function.');
                return $this->handleConfirmation($request);
            }
        }

        return response()->json(['message' => 'User information not found.'], 404);
    }

    private function handleConfirmation(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $reply_to_message = $request->message['message_id'] ?? null;
        $text = strtolower(trim($request->input('message.text')));
        $confirmationText = 'yes';
        if ($text === $confirmationText) {
            $userInformation = cache()->get("chat_id_{$chat_id}_user_info");
            if ($userInformation) {
                $this->handleYes($userInformation, $chat_id);
                app('telegram_bot')->sendMessage("บันทึกข้อมูลเรียบร้อยแล้ว", $chat_id, $reply_to_message);
            } else {
                app('telegram_bot')->sendMessage("ไม่พบข้อมูล user", $chat_id, $reply_to_message);
            }
        } elseif ($text === '/cancel') {
            app('telegram_bot')->sendMessage("ยกเลิกการ /setinfo", $chat_id, $reply_to_message);
        } else {
            app('telegram_bot')->sendMessage("กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ", $chat_id, $reply_to_message);
        }
        cache()->forget("chat_id_{$chat_id}_user_info");
    }
    public function handleYes(array $userInformation, $chat_id)
    {
        User::create([
            'name' => $userInformation['name'],
            'student_id' => $userInformation['student_id'],
            'phone_number' => $userInformation['phone_number'],
            'branch' => $userInformation['branch'],
            'company' => $userInformation['company'],
            'telegram_chat_id' => $chat_id
        ]);
    }
}