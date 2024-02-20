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

        if (strpos($request->message['text'], '/setinfo') !== false) {
            $userInfo = User::where('telegram_chat_id', $chat_id)->first();
            if ($userInfo) {
                $text = "คุณได้ตั้งค่าข้อมูลส่วนตัวของคุณไปแล้ว!\n";
                $text .= "ถ้าคุณต้องการแก้ไขข้อมูลให้ใช้คำสั่ง /editinfo";

                $result = app('telegram_bot')->sendMessage($chat_id, $text);

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

            $result = app('telegram_bot')->sendMessage($chat_id, $text);

            return response()->json($result, 200);
        }

        if (cache()->has("chat_id_{$chat_id}_user_info")) {
            return $this->confirmUserInfo($request);
        }

        if ($request->message['text'] === '/editinfo') {
            $userInfo = $this->getUserInfo($chat_id);
            if ($userInfo) {
                $text = "ต้องการแก้ไขข้อมูลใด:\n";
                $text .= "1. ชื่อ-นามสกุล: {$userInfo['name']}\n";
                $text .= "2. รหัสนิสิต: {$userInfo['student_id']}\n";
                $text .= "3. เบอร์โทรศัพท์: {$userInfo['phone_number']}\n";
                $text .= "4. สาขาวิชา: {$userInfo['branch']}\n";
                $text .= "5. สถานประกอบการ: {$userInfo['company']}\n";
                $text .= "กรุณาตอบเป็นตัวเลข(1-5)";
                cache()->put("chat_id_{$chat_id}_startEdit_userinfo", 'waiting_for_command', now()->addMinutes(60));
                $result = app('telegram_bot')->sendMessage($chat_id, $text);
                return response()->json($result, 200);
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัว";
                $result = app('telegram_bot')->sendMessage($chat_id, $text);

                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_startEdit_userinfo")) {
            $step = cache()->get("chat_id_{$chat_id}_startEdit_userinfo");
            $select = cache()->get("chat_id_{$chat_id}_select_choice_edit");
            $userInfo = $this->getUserInfo($chat_id);
            if ($step === 'waiting_for_command') {
                $selectedIndex = (int) $request->message['text'];
                if ($userInfo && is_array($userInfo->toArray()) && $selectedIndex >= 1 && $selectedIndex <= 5) {
                    $columnName = [
                        1 => 'ชื่อ-นามสกุล',
                        2 => 'รหัสนิสิต',
                        3 => 'เบอร์โทรศัพท์',
                        4 => 'สาขาวิชา',
                        5 => 'สถานประกอบการ'
                    ];
                    $text = "กรุณากรอกข้อมูลดังกล่าวใหม่\n";
                    $text .= "1. {$columnName[$selectedIndex]}\n";
                    cache()->put("chat_id_{$chat_id}_startEdit_userinfo", 'updated', now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_select_choice_edit", $selectedIndex, now()->addMinutes(60));
                    $reply_to_message = $request->message['message_id'] ?? null;
                    $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

                    return response()->json($result, 200);
                }
            } elseif ($step === 'updated') {
                $select = cache()->get("chat_id_{$chat_id}_select_choice_edit");
                $memoMessages = $request->message['text'];
                cache()->put("chat_id_{$chat_id}_edit_userInfo", $memoMessages, now()->addMinutes(60));
                $currentMemo = cache()->get("chat_id_{$chat_id}_edit_userInfo");
                $columnName = [
                    1 => 'ชื่อ-นามสกุล',
                    2 => 'รหัสนิสิต',
                    3 => 'เบอร์โทรศัพท์',
                    4 => 'สาขาวิชา',
                    5 => 'สถานประกอบการ'
                ];
                $text = "ข้อมูลที่แก้ไขใหม่\n";
                $text .= "{$columnName[$select]}: {$currentMemo}\n";
                $text .= "ถูกต้องไหมคะ?\n(กรุณาตอบ yes หรือ /cancel)";
                app('telegram_bot')->sendMessage($chat_id, $text);
                cache()->put("chat_id_{$chat_id}_startEdit_userinfo", 'waiting_for_time', now()->addMinutes(60));
            } elseif ($step === 'waiting_for_time') {
                $confirmationText = 'yes';
                $text = $request->message['text'];
                $textUpdate = cache()->get("chat_id_{$chat_id}_edit_userInfo");
                if ($text === $confirmationText) {
                    $userInformation = cache()->get("chat_id_{$chat_id}_select_choice_edit");
                    if ($userInformation) {
                        $columnName = [
                            1 => 'name',
                            2 => 'student_id',
                            3 => 'phone_number',
                            4 => 'branch',
                            5 => 'company'
                        ];
                        User::where('telegram_chat_id', $chat_id)->update([
                            $columnName[$userInformation] => $textUpdate
                        ]);

                        app('telegram_bot')->sendMessage("แก้ไขข้อมูลเรียบร้อยแล้ว", $chat_id);
                        cache()->forget("chat_id_{$chat_id}_edit_user_info");
                    } else {
                        app('telegram_bot')->sendMessage("ไม่พบข้อมูล user", $chat_id);
                    }
                } elseif ($text === '/cancel') {
                    app('telegram_bot')->sendMessage("ยกเลิกการ /editinfo", $chat_id);
                    cache()->forget("chat_id_{$chat_id}_edit_user_info");
                } else {
                    app('telegram_bot')->sendMessage("กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ", $chat_id);
                }
                cache()->forget("chat_id_{$chat_id}_edit_userInfo");
                cache()->forget("chat_id_{$chat_id}_startEdit_userinfo");
                cache()->forget("chat_id_{$chat_id}_select_choice_edit");
            }
        }

        if ($request->message['text'] === '/getinfo') {
            $userInfo = $this->getUserInfo($chat_id);
            if ($userInfo) {
                $text = "ข้อมูลส่วนตัวของคุณ:\n";
                $text .= "1. ชื่อ-นามสกุล: {$userInfo['name']}\n";
                $text .= "2. รหัสนิสิต: {$userInfo['student_id']}\n";
                $text .= "3. เบอร์โทรศัพท์: {$userInfo['phone_number']}\n";
                $text .= "4. สาขาวิชา: {$userInfo['branch']}\n";
                $text .= "5. สถานประกอบการ: {$userInfo['company']}\n";
                $text .= "หากต้องการแก้ไขข้อมูลส่วนตัว สามารถ /editinfo";
                $result = app('telegram_bot')->sendMessage($chat_id, $text);
                return response()->json($result, 200);
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัว";
                $result = app('telegram_bot')->sendMessage($chat_id, $text);

                return response()->json($result, 200);
            }
        }
    }

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

    public function getUserInfo($telegram_chat_id)
    {
        $userInfo = User::where('telegram_chat_id', $telegram_chat_id)->first();
        return $userInfo;
    }
}