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
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);

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
                        app('telegram_bot')->sendMessage($chat_id, "แก้ไขข้อมูลเรียบร้อยแล้ว");
                        cache()->forget("chat_id_{$chat_id}_edit_user_info");
                    } else {
                        app('telegram_bot')->sendMessage($chat_id, "ไม่พบข้อมูล user");
                    }
                } elseif ($text === '/cancel') {
                    app('telegram_bot')->sendMessage($chat_id, "ยกเลิกการ /editinfo");
                    cache()->forget("chat_id_{$chat_id}_edit_user_info");
                } else {
                    app('telegram_bot')->sendMessage($chat_id, "กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ");
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
        //reminder
        if ($request->message['text'] === '/setreminder') {
            $userInfo = $this->getUserInfo($chat_id);
            if ($userInfo) {
                return $this->setReminder($request);
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัว\nก่อนทำการแจ้งเตือนใดๆ";
                $result = app('telegram_bot')->sendMessage($chat_id, $text);
                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_setreminder")) {
            $step = cache()->get("chat_id_{$chat_id}_setreminder");
            $select = cache()->get("chat_id_{$chat_id}_select_type");
            if ($step === 'waiting_for_command') {
                $message = $request->message['text'];
                if ($message === '/formemo') {
                    $text = "ต้องการให้แจ้งเตือนจดบันทึกงานประจำวันกี่โมง?\n";
                    $text .= "กรุณาตอบในรูปแบบนาฬิกา 24 ชั่วโมง\n";
                    $text .= "ยกตัวอย่าง <10:00>\n";
                    cache()->put("chat_id_{$chat_id}_setreminder", 'waiting_for_time', now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_select_type", '/formemo', now()->addMinutes(60));
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);

                    return response()->json($result, 200);
                }
                if ($message === '/forsummary') {
                    $text = "ต้องการให้แจ้งเตือนสรุปงานประจำวันกี่โมง?\n";
                    $text .= "กรุณาตอบในรูปแบบนาฬิกา 24 ชั่วโมง\n";
                    $text .= "ยกตัวอย่าง <10:00>\n";
                    cache()->put("chat_id_{$chat_id}_setreminder", 'waiting_for_time', now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_select_type", '/forsummary', now()->addMinutes(60));
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);

                    return response()->json($result, 200);
                }
            } elseif ($step === 'waiting_for_time') {

                if ($select === '/formemo') {
                    $time = $request->message['text'];

                    $text = "ให้แจ้งเตือนเริ่มจดบันทึกงานประจำวันในเวลา\n";
                    $text .= "{$time} น. ใช่ไหมคะ?\n";
                    $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);
                    cache()->put("chat_id_{$chat_id}_setreminder", ['type' => '/formemo', 'time' => $time], now()->addMinutes(60));
                    cache()->forget("chat_id_{$chat_id}_select_type");
                    return response()->json($result, 200);
                }
                if ($select === '/forsummary') {
                    $time = $request->message['text'];

                    $text = "ให้แจ้งเตือนสรุปงานประจำวันในเวลา\n";
                    $text .= "{$time} น. ใช่ไหมคะ?\n";
                    $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);
                    cache()->put("chat_id_{$chat_id}_setreminder", ['type' => '/forsummary', 'time' => $time], now()->addMinutes(60));
                    cache()->forget("chat_id_{$chat_id}_select_type");
                    return response()->json($result, 200);
                }
            }
            return $this->handleReminderConfirmation($request);
        }

        if ($request->message['text'] === '/editreminder') {
            $userInfo = $this->getUserInfo($chat_id);
            if ($userInfo) {
                return $this->editReminder($request);
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัว\nก่อนทำการแจ้งเตือนใดๆ";
                $result = app('telegram_bot')->sendMessage($chat_id, $text);
                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_editreminder")) {
            $step = cache()->get("chat_id_{$chat_id}_editreminder");
            $select = cache()->get("chat_id_{$chat_id}_select_type_edit");
            if ($step === 'waiting_for_command') {
                $message = $request->message['text'];
                if ($message === '1') {
                    $text = "ต้องการแก้ไขเวลาแจ้งเตือนจดบันทึกงานประจำวันกี่โมง?\n";
                    $text .= "กรุณาตอบในรูปแบบนาฬิกา 24 ชั่วโมง\n";
                    $text .= "ยกตัวอย่าง <10:00>\n";
                    cache()->put("chat_id_{$chat_id}_editreminder", 'waiting_for_time', now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_select_type_edit", '/formemo', now()->addMinutes(60));
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);

                    return response()->json($result, 200);
                }
                if ($message === '2') {
                    $text = "ต้องการแก้ไขเวลาสรุปงานประจำวันกี่โมง?\n";
                    $text .= "กรุณาตอบในรูปแบบนาฬิกา 24 ชั่วโมง\n";
                    $text .= "ยกตัวอย่าง <10:00>\n";
                    cache()->put("chat_id_{$chat_id}_editreminder", 'waiting_for_time', now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_select_type_edit", '/forsummary', now()->addMinutes(60));
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);

                    return response()->json($result, 200);
                }
            } elseif ($step === 'waiting_for_time') {

                if ($select === '/formemo') {
                    $time = $request->message['text'];

                    $text = "ให้แจ้งเตือนเริ่มจดบันทึกงานประจำวันในเวลา\n";
                    $text .= "{$time} น. ใช่ไหมคะ?\n";
                    $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);
                    cache()->put("chat_id_{$chat_id}_editreminder", ['type' => '/formemo', 'time' => $time], now()->addMinutes(60));
                    cache()->forget("chat_id_{$chat_id}_select_type_edit");
                    return response()->json($result, 200);
                }
                if ($select === '/forsummary') {
                    $time = $request->message['text'];

                    $text = "ให้แจ้งเตือนสรุปงานประจำวันในเวลา\n";
                    $text .= "{$time} น. ใช่ไหมคะ?\n";
                    $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);
                    cache()->put("chat_id_{$chat_id}_editreminder", ['type' => '/forsummary', 'time' => $time], now()->addMinutes(60));
                    cache()->forget("chat_id_{$chat_id}_select_type_edit");
                    return response()->json($result, 200);
                }
            }
            return $this->handleEditReminderConfirmation($request);
        }

        if ($request->message['text'] === '/getreminder') {
            $userInfo = $this->getUserInfo($chat_id);
            if ($userInfo) {
                $userInfo = $this->getReminder($chat_id);

                if (!empty($userInfo['memo_time'] && $userInfo['summary_time'])) {
                    $memoTime = Carbon::createFromFormat('H:i:s', $userInfo['memo_time'])->format('H:i');
                    $summaryTime = Carbon::createFromFormat('H:i:s', $userInfo['summary_time'])->format('H:i');
                    $text = "แจ้งเตือนการจดบันทึกประจำวันเวลา: {$memoTime} น.\n";
                    $text .= "แจ้งเตือนสรุปงานประจำวันเวลา: {$summaryTime} น.\n";
                    $text .= "หากต้องการแก้ไข สามารถ /editreminder";
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);
                    return response()->json($result, 200);
                } elseif (!empty($userInfo['memo_time']) && empty($userInfo['summary_time'])) {
                    $memoTime = Carbon::createFromFormat('H:i:s', $userInfo['memo_time'])->format('H:i');
                    $text = "แจ้งเตือนการจดบันทึกประจำวันเวลา: {$memoTime} น.\n";
                    $text .= "คุณยังไม่ได้สรุปงานประจำวัน!\n";
                    $text .= "กรุณา /setreminder เพื่อตั้งค่าเวลาสรุปงานประจำวัน";
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);
                    return response()->json($result, 200);
                } elseif (empty($userInfo['memo_time']) && !empty($userInfo['summary_time'])) {
                    $summaryTime = Carbon::createFromFormat('H:i:s', $userInfo['summary_time'])->format('H:i');
                    $text = "คุณยังไม่ได้ตั้งค่าเวลาแจ้งเตือนจดบันทึกประจำวัน!\n";
                    $text .= "แจ้งเตือนสรุปงานประจำวันเวลา: {$summaryTime} น.\n";
                    $text .= "กรุณา /setreminder เพื่อตั้งค่าเวลาจดบันทึกประจำวัน";
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);
                    return response()->json($result, 200);
                } else {
                    $text = "คุณยังไม่ได้ตั้งค่าเวลาแจ้งเตือนใดๆ!\n";
                    $text .= "กรุณา /setreminder เพื่อตั้งค่าเวลาแจ้งเตือน";
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);

                    return response()->json($result, 200);
                }
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัว\nก่อนทำการแจ้งเตือนใดๆ";
                $result = app('telegram_bot')->sendMessage($chat_id, $text);
                return response()->json($result, 200);
            }
        }
        //memo
        if ($request->message['text'] === '/memo') {
            $userInfo = $this->getUserInfo($chat_id);
            if ($userInfo) {
                return $this->memoDairy($request);
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัวก่อนทำการจดบันทึกใดๆ";
                $result = app('telegram_bot')->sendMessage($chat_id, $text);
                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_startMemoDairy")) {
            $step = cache()->get("chat_id_{$chat_id}_startMemoDairy");
            if ($step === 'waiting_for_command') {
                $memoMessage = $request->message['text'];
                if ($memoMessage === '/end') {
                    $currentMemo = cache()->get("chat_id_{$chat_id}_memoDaily");
                    if ($currentMemo !== null) {
                        $formattedMemo = [];
                        foreach ($currentMemo as $key => $memo) {
                            $formattedMemo[] = ($key + 1) . ". " . $memo;
                        }
                        $text = "งานที่บันทึกในตอนนี้:\n" . implode("\n", $formattedMemo);
                        $text .= "\nถูกต้องมั้ยคะ? (กรุณาตอบ yes หรือ /cancel)\n";
                        app('telegram_bot')->sendMessage($chat_id, $text);
                        cache()->put("chat_id_{$chat_id}_startAddMemoDairy", 'waiting_for_time', now()->addMinutes(60));
                    } else {
                        $text = "\nกรุณาเพิ่มบันทึกประจำวันใหม่อีกครั้ง\nเมื่อจดบันทึกครบแล้ว ให้พิมพ์ /end เพื่อจบการบันทึก";
                        app('telegram_bot')->sendMessage($chat_id, $text);
                        cache()->put("chat_id_{$chat_id}_startAddMemoDairy", 'waiting_for_command', now()->addMinutes(60));
                    }
                } else {
                    $memoMessages = cache()->get("chat_id_{$chat_id}_memoDaily", []);
                    $memoMessages[] = $memoMessage;
                    cache()->put("chat_id_{$chat_id}_memoDaily", $memoMessages, now()->addMinutes(60));
                }
            } elseif ($step === 'waiting_for_time') {
                $confirmationText = 'yes';
                $text_reply = '';
                $text = $request->message['text'];
                if ($text === $confirmationText) {
                    $currentMemo = cache()->get("chat_id_{$chat_id}_memoDaily");
                    $currentTime = Carbon::now()->toDateString();
                    if ($currentMemo && Memo::where('user_id', $chat_id)->whereDate('memo_date', $currentTime)->exists()) {
                        $formattedMemo = implode(', ', $currentMemo);
                        Memo::where('user_id', $chat_id)->where('memo_date', $currentTime)->update(['memo' => $formattedMemo]);
                        $text_reply = "บันทึกงานประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                    } elseif ($currentMemo) {
                        $formattedMemo = implode(', ', $currentMemo);
                        Memo::create(['user_id' => $chat_id, 'memo' => $formattedMemo, 'memo_date' => $currentTime]);

                        $text_reply = "บันทึกงานประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                    } else {
                        $text_reply = "ไม่มีงานประจำวันที่จะบันทึกในขณะนี้ค่ะ!";
                    }

                    app('telegram_bot')->sendMessage($chat_id, $text_reply);
                } elseif ($text === '/cancel') {
                    app('telegram_bot')->sendMessage($chat_id, "ยกเลิกการ /memo");
                } else {
                    app('telegram_bot')->sendMessage($chat_id, "กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ");
                }
                cache()->forget("chat_id_{$chat_id}_startMemoDairy");
                cache()->forget("chat_id_{$chat_id}_memoDaily");
            }
        }

        if ($request->message['text'] === '/getmemo') {
            $userInfo = $this->getUserInfo($chat_id);
            if ($userInfo) {

                $userMemo = $this->getUserMemo($chat_id);
                if (!$userMemo || (!$userMemo['memo'] && !$userMemo['note_today'])) {

                    $text = "คุณยังไม่ได้จดบันทึกงานประจำวัน!\n";
                    $text .= "กรุณา /memo เพื่อเริ่มจดบันทึกประจำวัน";
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);

                    return response()->json($result, 200);
                } elseif ($userMemo['memo']) {

                    $memoArray = explode(', ', $userMemo['memo']);
                    $formattedMemo = [];
                    foreach ($memoArray as $key => $memo) {
                        $formattedMemo[] = ($key + 1) . ". " . $memo;
                    }
                    $text = "งานที่บันทึกในตอนนี้:\n" . implode("\n", $formattedMemo);
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
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);
                    return response()->json($result, 200);
                } elseif ($userMemo['note_today'] && empty($userMemo['memo'])) {
                    $text = "หมายเหตุประจำวัน:\n{$userMemo['note_today']}";
                    $text .= "\n\nหรือคุณต้องการ\n";
                    $text .= "   /memo - เริ่มจดบันทึกงานประจำวัน\n";
                    $text .= "   /addmemo - เพิ่มบันทึกงานประจำวัน\n";
                    $text .= "   /editmemo - แก้ไขบันทึกงานประจำวัน\n";
                    $text .= "   /getmemo - เรียกดูบันทึกงานประจำวัน\n\n";
                    $text .= "   /notetoday - เพิ่มหมายเหตุกรณีเป็นวันหยุด หรือวันลา\n\n";
                    $text .= "   หากต้องการล้างบันทึก/หมายเหตุประจำวัน สามารถ\n";
                    $text .= "   /resetmemo - ล้างบันทึกงานประจำวัน\n";
                    $text .= "   /resetnotetoday - ล้างหมายเหตุประจำวัน\n\n";
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);
                    return response()->json($result, 200);
                }
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัวก่อนทำการจดบันทึกใดๆ";
                $result = app('telegram_bot')->sendMessage($chat_id, $text);
                return response()->json($result, 200);
            }
        }

        if ($request->message['text'] === '/addmemo') {
            $userInfo = $this->getUserInfo($chat_id);
            if ($userInfo) {
                return $this->addMemoDairy($request);
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัวก่อนทำการจดบันทึกใดๆ";
                $result = app('telegram_bot')->sendMessage($chat_id, $text);
                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_startAddMemoDairy")) {
            $step = cache()->get("chat_id_{$chat_id}_startAddMemoDairy");
            if ($step === 'waiting_for_command') {
                $memoMessage = $request->message['text'];
                $userMemo = $this->getUserMemo($chat_id);
                $memoArray = explode(', ', $userMemo['memo']);
                if ($memoMessage === '/end') {
                    $currentMemo = cache()->get("chat_id_{$chat_id}_addMemoDaily"); //case null
                    if ($currentMemo !== null) {
                        $formattedMemo = [];
                        foreach ($currentMemo as $key => $memo) {
                            $formattedMemo[] = ($key + 1) . ". " . $memo;
                        }
                        $text = "งานที่บันทึกในตอนนี้:\n" . implode("\n", $formattedMemo);
                        $text .= "\nถูกต้องมั้ยคะ? (กรุณาตอบ yes หรือ /cancel)\n";
                        app('telegram_bot')->sendMessage($chat_id, $text);
                        cache()->put("chat_id_{$chat_id}_startAddMemoDairy", 'waiting_for_time', now()->addMinutes(60));
                    } else {
                        $text = "\nกรุณาเพิ่มบันทึกประจำวันใหม่อีกครั้ง\nเมื่อจดบันทึกครบแล้ว ให้พิมพ์ /end เพื่อจบการบันทึก";
                        app('telegram_bot')->sendMessage($chat_id, $text);
                        cache()->put("chat_id_{$chat_id}_startAddMemoDairy", 'waiting_for_command', now()->addMinutes(60));
                    }
                } else {
                    $memoArray = cache()->get("chat_id_{$chat_id}_addMemoDaily", $memoArray);
                    $memoArray[] = $memoMessage;
                    cache()->put("chat_id_{$chat_id}_addMemoDaily", $memoArray, now()->addMinutes(60));
                }
            } elseif ($step === 'waiting_for_time') {
                $confirmationText = 'yes';
                $text_reply = '';
                $text = $request->message['text'];
                if ($text === $confirmationText) {
                    $currentMemo = cache()->get("chat_id_{$chat_id}_addMemoDaily");

                    if (!empty($currentMemo)) {
                        $formattedMemo = implode(', ', $currentMemo);
                        $currentDate = Carbon::now()->toDateString();
                        Memo::where('user_id', $chat_id)->where('memo_date', $currentDate)->update(['memo' => $formattedMemo,]);
                        $text_reply = "บันทึกงานประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                    } else {
                        $text_reply = "ไม่มีงานประจำวันที่จะบันทึกในขณะนี้ค่ะ!";
                    }

                    app('telegram_bot')->sendMessage($chat_id, $text_reply);
                } elseif ($text === '/cancel') {
                    app('telegram_bot')->sendMessage($chat_id, "ยกเลิกการ /addmemo");
                } else {
                    app('telegram_bot')->sendMessage($chat_id, "กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ");
                }
                cache()->forget("chat_id_{$chat_id}_startAddMemoDairy");
                cache()->forget("chat_id_{$chat_id}_addMemoDaily");
            }
        }

        if ($request->message['text'] === '/editmemo') {
            $userInfo = $this->getUserInfo($chat_id);
            if ($userInfo) {
                return $this->editMemoDairy($request);
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัวก่อนทำการจดบันทึกใดๆ";
                $result = app('telegram_bot')->sendMessage($chat_id, $text);
                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_editMemoDairy")) {
            $step = cache()->get("chat_id_{$chat_id}_editMemoDairy");
            $select = cache()->get("chat_id_{$chat_id}_select_choice_edit");
            $userMemo = $this->getUserMemo($chat_id);
            $memoMessages = explode(', ', $userMemo['memo']);

            if ($step === 'waiting_for_command') {
                $selectedIndex = $request->message['text'];
                if ($selectedIndex >= 1 && $selectedIndex <= count($memoMessages)) {
                    $text = "สามารถพิมพ์ข้อความเพื่อแก้ไขงานประจำวันได้เลยค่ะ\n";
                    $text .= "(สามารถแก้ไขได้เพียงข้อที่เลือก)\n";
                    $text .= "'Create function CRUD'\n";
                    cache()->put("chat_id_{$chat_id}_editMemoDairy", 'updated', now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_select_choice_edit", $selectedIndex, now()->addMinutes(60));
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);

                    return response()->json($result, 200);
                }
            } elseif ($step === 'updated') {
                $select = cache()->get("chat_id_{$chat_id}_select_choice_edit");
                $memoMessages[$select - 1] = $request->message['text'];
                cache()->put("chat_id_{$chat_id}_memoDaily", $memoMessages, now()->addMinutes(60));
                $currentMemo = cache()->get("chat_id_{$chat_id}_memoDaily");
                $formattedMemo = [];
                foreach ($currentMemo as $key => $memo) {
                    $formattedMemo[] = ($key + 1) . ". " . $memo;
                }
                $text = "งานที่บันทึกในตอนนี้:\n" . implode("\n", $formattedMemo);
                $text .= "\nถูกต้องมั้ยคะ? (กรุณาตอบ yes หรือ /cancel)\n";
                app('telegram_bot')->sendMessage($chat_id, $text);
                cache()->put("chat_id_{$chat_id}_editMemoDairy", 'waiting_for_time', now()->addMinutes(60));
            } elseif ($step === 'waiting_for_time') {
                $confirmationText = 'yes';
                $text_reply = '';
                $text = $request->message['text'];
                if ($text === $confirmationText) {
                    $currentMemo = cache()->get("chat_id_{$chat_id}_memoDaily");

                    if (!empty($currentMemo)) {
                        $formattedMemo = implode(', ', $currentMemo);
                        $currentDate = Carbon::now()->toDateString();
                        Memo::where('user_id', $chat_id)->where('memo_date', $currentDate)->update(['memo' => $formattedMemo,]);
                        $text_reply = "บันทึกงานประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                    } else {
                        $text_reply = "ไม่มีงานประจำวันที่จะบันทึกในขณะนี้ค่ะ!";
                    }

                    app('telegram_bot')->sendMessage($chat_id, $text_reply);
                } elseif ($text === '/cancel') {
                    app('telegram_bot')->sendMessage($chat_id, "ยกเลิกการ /editmemo");
                } else {
                    app('telegram_bot')->sendMessage($chat_id, "กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ");
                }
                cache()->forget("chat_id_{$chat_id}_memoDaily");
                cache()->forget("chat_id_{$chat_id}_editMemoDairy");
                cache()->forget("chat_id_{$chat_id}_select_choice_edit");
            }
        }

        if ($request->message['text'] === '/resetmemo') {
            $userInfo = $this->getUserInfo($chat_id);
            if ($userInfo) {
                $userMemo = $this->getUserMemo($chat_id);
                if ($userMemo['memo']) {
                    $memoArray = explode(', ', $userMemo['memo']);
                    $formattedMemo = [];
                    foreach ($memoArray as $key => $memo) {
                        $formattedMemo[] = ($key + 1) . ". " . $memo;
                    }
                    $text = "งานที่บันทึกในตอนนี้:\n" . implode("\n", $formattedMemo);
                    $text .= "\nคุณต้องการล้างบันทึกประจำวันเพื่อเริ่มจดบันทึกใหม่หรือไม่?";
                    $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
                    cache()->put("chat_id_{$chat_id}_startResetMemoDairy", true, now()->addMinutes(60));
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);
                    return response()->json($result, 200);
                } else {
                    $text = "คุณยังไม่ได้จดบันทึกงานประจำวัน!\n";
                    $text .= "กรุณา /memo เพื่อเริ่มจดบันทึกประจำวัน";
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);
                    return response()->json($result, 200);
                }
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัวก่อนทำการจดบันทึกใดๆ";
                $result = app('telegram_bot')->sendMessage($chat_id, $text);
                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_startResetMemoDairy")) {
            $confirmationText = 'yes';
            $text_reply = '';
            $text = $request->message['text'];
            $userMemo = $this->getUserMemo($chat_id);
            if ($text === $confirmationText) {
                $userMemo->memo = null;
                $userMemo->save();
                $text_reply = "ล้างบันทึกงานประจำวันเรียบร้อยแล้ว!\n";
                $text_reply .= "สามารถ /memo เพื่อเริ่มจดบันทึกประจำวันใหม่อีกครั้ง";
                app('telegram_bot')->sendMessage($chat_id, $text_reply);
            } elseif ($text === '/cancel') {
                app('telegram_bot')->sendMessage($chat_id, "ยกเลิกการ /resetmemo");
            } else {
                app('telegram_bot')->sendMessage($chat_id, "กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ");
            }
            cache()->forget("chat_id_{$chat_id}_startResetMemoDairy");
        }

        if ($request->message['text'] === '/resetnotetoday') {
            $userInfo = $this->getUserInfo($chat_id);
            if ($userInfo) {
                $userMemo = $this->getUserMemo($chat_id);//check ว่า ถ้าไม่เคยบันทึกเลยในวันนี้
                if ($userMemo['note_today']) {
                    $text = "หมายเหตุประจำวันตอนนี้:\n{$userMemo['note_today']}";
                    $text .= "\nคุณต้องการล้างหมายเหตุประจำวันเพื่อเริ่มจดบันทึกใหม่หรือไม่?";
                    $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
                    cache()->put("chat_id_{$chat_id}_startResetnotetoday", true, now()->addMinutes(60));
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);
                    return response()->json($result, 200);
                } elseif (!$userMemo['note_today']) {
                    $text = "คุณยังไม่ได้เพิ่มหมายเหตุประจำวัน!\n";
                    $text .= "กรุณา /notetoday เพิ่มหมายเหตุประจำวัน";
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);
                    return response()->json($result, 200);
                }
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัวก่อนทำการจดบันทึกใดๆ";
                $result = app('telegram_bot')->sendMessage($chat_id, $text);
                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_startResetnotetoday")) {
            $confirmationText = 'yes';
            $text_reply = '';
            $text = $request->message['text'];
            $userMemo = $this->getUserMemo($chat_id);
            if ($text === $confirmationText) {
                $userMemo->note_today = null;
                $userMemo->save();
                $text_reply = "ล้างหมายเหตุประจำวันเรียบร้อยแล้ว!\n";
                $text_reply .= "สามารถ /notetoday เพื่อเริ่มจดบันทึกประจำวันใหม่อีกครั้ง";
                app('telegram_bot')->sendMessage($chat_id, $text_reply);
            } elseif ($text === '/cancel') {
                app('telegram_bot')->sendMessage($chat_id, "ยกเลิกการ /resetnotetoday");
            } else {
                app('telegram_bot')->sendMessage($chat_id, "กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ");
            }
            cache()->forget("chat_id_{$chat_id}_startResetnotetoday");
        }

        if ($request->message['text'] === '/notetoday') {
            $userInfo = $this->getUserInfo($chat_id);
            if ($userInfo) {
                $userMemo = $this->getUserMemo($chat_id);
                if (!$userMemo || !$userMemo['note_today']) {
                    $text = "สามารถพิมพ์ข้อความใดๆเพื่อเพิ่มหมายเหตุได้เลยค่ะ\n";
                    $text .= "ยกตัวอย่าง ‘วันหยุดปีใหม่’\n";
                    cache()->put("chat_id_{$chat_id}_startNoteMemoDairy", 'waiting_for_command', now()->addMinutes(60));
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);
                    return response()->json($result, 200);
                } else {
                    $text = "คุณเริ่มจดหมายเหตุประจำวันไปแล้ว!\n\n";
                    $text .= "หรือคุณต้องการ\n";
                    $text .= "   /resetmemo - ล้างบันทึกงานประจำวัน\n";
                    $text .= "   /resetnotetoday - ล้างหมายเหตุประจำวัน\n\n";
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);
                    return response()->json($result, 200);
                }
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัวก่อนทำการจดบันทึกใดๆ";
                $result = app('telegram_bot')->sendMessage($chat_id, $text);
                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_startNoteMemoDairy")) {
            $step = cache()->get("chat_id_{$chat_id}_startNoteMemoDairy");
            if ($step === 'waiting_for_command') {
                $notetoday = $request->message['text'];

                $text = "หมายเหตุของวันนี้:\n";
                $text .= "{$notetoday}\nถูกต้องมั้ยคะ?";
                $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
                cache()->put("chat_id_{$chat_id}_startNoteMemoDairy", 'confirm', now()->addMinutes(60));
                cache()->put("chat_id_{$chat_id}_noteToday", $notetoday, now()->addMinutes(60));
                $reply_to_message = $request->message['message_id'] ?? null;
                $result = app('telegram_bot')->sendMessage($chat_id, $text);

            } elseif ($step === 'confirm') {
                $confirmationText = 'yes';
                $text_reply = '';
                $text = $request->message['text'];
                if ($text === $confirmationText) {
                    $currentNoteToday = cache()->get("chat_id_{$chat_id}_noteToday");
                    $currentTime = Carbon::now()->toDateString();

                    if ($currentNoteToday && Memo::where('user_id', $chat_id)->whereDate('memo_date', $currentTime)->exists()) {
                        Memo::where('user_id', $chat_id)->where('memo_date', $currentTime)->update(['note_today' => $currentNoteToday]);
                        $text_reply = "บันทึกหมายเหตุประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                    } elseif ($currentNoteToday) {
                        Memo::create(['user_id' => $chat_id, 'note_today' => $currentNoteToday, 'memo_date' => $currentTime]);
                        $text_reply = "บันทึกหมายเหตุประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                    } else {
                        $text_reply = "ไม่มีหมายเหตุประจำวันที่จะบันทึกในขณะนี้ค่ะ!";
                    }
                    app('telegram_bot')->sendMessage($chat_id, $text_reply);
                } elseif ($text === '/cancel') {
                    app('telegram_bot')->sendMessage($chat_id, "ยกเลิกการ /notetoday");
                } else {
                    app('telegram_bot')->sendMessage($chat_id, "กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ");
                }
                cache()->forget("chat_id_{$chat_id}_startNoteMemoDairy");
                cache()->forget("chat_id_{$chat_id}_noteToday");
            }
        }
        //generatememo
        if ($request->message['text'] === '/generateDoc') {
            $documentPath = $this->generateDocument($request);
            $result = app('telegram_bot')->sendDocument($chat_id, $documentPath);
            return response()->json($result, 200);
        }
    }
    public function generateDocument(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $userInfo = $this->getUserInfo($chat_id);
        $directory = 'word-send';
        if (!file_exists(public_path($directory))) {
            mkdir(public_path($directory), 0777, true);
        }
        $templateProcessor = new TemplateProcessor('word-template/user.docx');
        $currentWeekStartDate = Carbon::now()->startOfWeek();
        $currentWeekNumber = $currentWeekStartDate->weekOfYear;

        $memos = Memo::where('user_id', $chat_id)
            ->whereBetween('memo_date', [$currentWeekStartDate, $currentWeekStartDate->copy()->endOfWeek()])
            ->get();

        foreach ($memos as $memo) {
            $weekdayIndex = Carbon::parse($memo->memo_date)->dayOfWeekIso;
            $templateProcessor->setValue("number_of_week", $currentWeekNumber);
            $templateProcessor->setValue("memo_date_$weekdayIndex", $memo->memo_date);
            for ($i = 0; $i < 5; $i++) {
                $templateProcessor->setValue("memo[$i]_$weekdayIndex", $this->getMemo($memo->memo, $i));
            }
            $templateProcessor->setValue("note_today_$weekdayIndex", $memo->note_today);
        }
        $fileName = $userInfo['student_id'] . '_week' . $currentWeekNumber . '_memo.docx';
        $filePath = public_path($directory . DIRECTORY_SEPARATOR . $fileName);
        $templateProcessor->saveAs($filePath);

        return $filePath;
    }
    public function confirmUserInfo(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
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

                $result = app('telegram_bot')->sendMessage($chat_id, $text);

                cache()->put("chat_id_{$chat_id}_user_info", compact('name', 'student_id', 'phone_number', 'branch', 'company'), now()->addMinutes(10));
                cache()->put("chat_id_{$chat_id}_user_info_confirm", true, now()->addMinutes(10));
                return response()->json($result, 200);
            }

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


    public function setReminder(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $userInfo = $this->getReminder($chat_id);
        if ($userInfo['memo_time'] && $userInfo['summary_time']) {
            $text = "คุณตั้งค่าเวลาแจ้งเตือนจดบันทึกงานประจำวัน\n";
            $text .= "และตั้งค่าเวลาสรุปงานประจำวัน เรียบร้อยแล้ว!\n";
            $text .= "หากต้องการแก้ไข สามารถ /editreminder";
            $result = app('telegram_bot')->sendMessage($chat_id, $text);

            return response()->json($result, 200);
        } else if ($userInfo['memo_time'] && !$userInfo['summary_time']) {
            $text = "คุณตั้งค่าเวลาแจ้งเตือนจดบันทึกงานประจำวัน เรียบร้อยแล้ว!\n";
            $text .= "กรุณา /forsummary เพื่อตั้งค่าแจ้งเตือนสรุปงานประจำวัน\n";
            $result = app('telegram_bot')->sendMessage($chat_id, $text);
            cache()->put("chat_id_{$chat_id}_setreminder", 'waiting_for_command', now()->addMinutes(60));

            return response()->json($result, 200);
        } else if (!$userInfo['memo_time'] && $userInfo['summary_time']) {
            $text = "คุณตั้งค่าเวลาแจ้งเตือนจดบันทึกงานประจำวัน เรียบร้อยแล้ว!\n";
            $text .= "กรุณา /formemo เพื่อตั้งค่าแจ้งเตือนสรุปงานประจำวัน\n";
            $result = app('telegram_bot')->sendMessage($chat_id, $text);
            cache()->put("chat_id_{$chat_id}_setreminder", 'waiting_for_command', now()->addMinutes(60));

            return response()->json($result, 200);
        } else {
            $text = "กรุณาเลือกประเภทการแจ้งเตือนเพื่อตั้งค่าเวลา:\n";
            $text .= "1. /formemo - แจ้งเตือนจดบันทึกงานประจำวัน\n";
            $text .= "2. /forsummary - แจ้งเตือนสรุปงานประจำวัน\n";
            $result = app('telegram_bot')->sendMessage($chat_id, $text);

            cache()->put("chat_id_{$chat_id}_setreminder", 'waiting_for_command', now()->addMinutes(60));

            return response()->json($result, 200);
        }

    }

    public function editReminder(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $text = "กรุณาเลือกประเภทการแจ้งเตือนเพื่อแก้ไขเวลา:\n";
        $text .= "1. แก้ไขเวลาแจ้งเตือนจดบันทึกงานประจำวัน\n";
        $text .= "2. แก้ไขเวลาแจ้งเตือนสรุปงานประจำวัน\n";
        $text .= "กรุณาตอบเป็นตัวเลข(1-2)\n";
        $result = app('telegram_bot')->sendMessage($chat_id, $text);

        cache()->put("chat_id_{$chat_id}_editreminder", 'waiting_for_command', now()->addMinutes(60));

        return response()->json($result, 200);
    }

    private function handleReminderConfirmation(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $text = strtolower(trim($request->input('message.text')));
        $confirmationText = 'yes';
        $text_reply = '';
        if ($text === $confirmationText) {
            $setReminderTime = cache()->get("chat_id_{$chat_id}_setreminder");
            if ($setReminderTime) {
                switch ($setReminderTime['type']) {
                    case '/formemo':
                        User::where('telegram_chat_id', $chat_id)->update([
                            'memo_time' => $setReminderTime['time'],
                        ]);
                        $text_reply = "ตั้งค่าเวลาแจ้งเตือนเริ่มจดบันทึกงานประจำวันเรียบร้อยแล้ว!";
                        break;
                    case '/forsummary':
                        User::where('telegram_chat_id', $chat_id)->update([
                            'summary_time' => $setReminderTime['time'],
                        ]);
                        $text_reply = "ตั้งค่าเวลาแจ้งเตือนสรุปงานประจำวันเรียบร้อยแล้ว!";
                        break;
                    default:
                        break;
                }
                app('telegram_bot')->sendMessage($chat_id, $text_reply);
                cache()->forget("chat_id_{$chat_id}_setreminder");
            } else {
                app('telegram_bot')->sendMessage($chat_id, "ไม่พบข้อมูล user");
            }
        } elseif ($text === '/cancel') {
            app('telegram_bot')->sendMessage($chat_id, "ยกเลิกการ /setreminder");
            cache()->forget("chat_id_{$chat_id}_setreminder");
        } else {
            app('telegram_bot')->sendMessage($chat_id, "กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ");
        }
    }

    private function handleEditReminderConfirmation(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $text = strtolower(trim($request->input('message.text')));
        $confirmationText = 'yes';
        $text_reply = '';
        if ($text === $confirmationText) {
            $setReminderTime = cache()->get("chat_id_{$chat_id}_editreminder");
            if ($setReminderTime) {

                switch ($setReminderTime['type']) {
                    case '/formemo':
                        User::where('telegram_chat_id', $chat_id)->update([
                            'memo_time' => $setReminderTime['time'],
                        ]);
                        $text_reply = "แก้ไขเวลาแจ้งเตือนเริ่มจดบันทึกงานประจำวันเรียบร้อยแล้ว!";
                        break;
                    case '/forsummary':
                        User::where('telegram_chat_id', $chat_id)->update([
                            'summary_time' => $setReminderTime['time'],
                        ]);
                        $text_reply = "แก้ไขเวลาแจ้งเตือนสรุปงานประจำวันเรียบร้อยแล้ว!";
                        break;
                    default:
                        break;
                }
                app('telegram_bot')->sendMessage($chat_id, $text_reply);
                cache()->forget("chat_id_{$chat_id}_editreminder");
            } else {
                app('telegram_bot')->sendMessage($chat_id, "ไม่พบข้อมูล user");
            }
        } elseif ($text === '/cancel') {
            app('telegram_bot')->sendMessage($chat_id, "ยกเลิกการ /editreminder");
            cache()->forget("chat_id_{$chat_id}_editreminder");
        } else {
            app('telegram_bot')->sendMessage($chat_id, "กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ");
        }
    }
    public function getReminder($telegram_chat_id)
    {
        $userReminder = User::where('telegram_chat_id', $telegram_chat_id)->first();
        return $userReminder;
    }
    //memo

    private function getMemo($memo, $index)
    {
        $memoArray = explode(',', $memo);
        return isset($memoArray[$index]) ? trim($memoArray[$index]) : '……………………………………………………………………………………';
    }
    public function editMemoDairy(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $userMemo = $this->getUserMemo($chat_id);
        if (!$userMemo || !$userMemo['memo'] || (!$userMemo['memo'] && !$userMemo['note_today'])) {
            $text = "คุณยังไม่ได้จดบันทึกงานประจำวัน!\n";
            $text .= "กรุณา /memo เพื่อเริ่มจดบันทึกประจำวัน";
            $result = app('telegram_bot')->sendMessage($chat_id, $text);
            return response()->json($result, 200);
        } elseif ($userMemo['memo']) {
            $currentMemo = explode(', ', $userMemo['memo']);
            $formattedMemo = [];
            foreach ($currentMemo as $key => $memo) {
                $formattedMemo[] = ($key + 1) . ". " . $memo;
            }
            $text = "กรุณาเลือกบันทึกที่ต้องการแก้ไข:\n" . implode("\n", $formattedMemo);
            $text .= "\nกรุณาตอบเพียงตัวเลขเดียวเท่านั้น ";
            cache()->put("chat_id_{$chat_id}_editMemoDairy", 'waiting_for_command', now()->addMinutes(60));
            $result = app('telegram_bot')->sendMessage($chat_id, $text);
            return response()->json($result, 200);
        }
    }
    public function addMemoDairy(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $userMemo = $this->getUserMemo($chat_id);
        if (!$userMemo || !$userMemo['memo'] || (!$userMemo['memo'] && !$userMemo['note_today'])) {
            $text = "คุณยังไม่ได้จดบันทึกงานประจำวัน!\n";
            $text .= "กรุณา /memo เพื่อเริ่มจดบันทึกประจำวัน";
            $result = app('telegram_bot')->sendMessage($chat_id, $text);
            return response()->json($result, 200);
        } elseif ($userMemo['memo']) {
            $text = "สามารถพิมพ์ข้อความใดๆเพื่อเพิ่มบันทึกงานประจำวันได้เลยค่ะ\n";
            $text .= "ยกตัวอย่าง 'Create function CRUD'\n";
            $text .= "เมื่อจดบันทึกครบแล้ว ให้พิมพ์ /end เพื่อจบการบันทึก\n";
            $result = app('telegram_bot')->sendMessage($chat_id, $text);
            cache()->put("chat_id_{$chat_id}_startAddMemoDairy", 'waiting_for_command', now()->addMinutes(60));
            return response()->json($result, 200);
        }
    }
    public function memoDairy(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $userMemo = $this->getUserMemo($chat_id);
        if (!$userMemo || !$userMemo['memo']) {
            $text = "สามารถพิมพ์ข้อความใดๆเพื่อจดบันทึกงานประจำวันได้เลยค่ะ\n";
            $text .= "ยกตัวอย่าง 'Create function CRUD'\n";
            $text .= "เมื่อจดบันทึกครบแล้ว ให้พิมพ์ /end เพื่อจบการบันทึก\n";
            $result = app('telegram_bot')->sendMessage($chat_id, $text);
            cache()->put("chat_id_{$chat_id}_startMemoDairy", 'waiting_for_command', now()->addMinutes(60));
            cache()->put("chat_id_{$chat_id}_memoDaily", [], now()->addMinutes(60));
            return response()->json($result, 200);
        } else {
            $text = "คุณเริ่มจดบันทึกประจำวันไปแล้ว!\n\n";
            $text .= "หรือคุณต้องการ\n";
            $text .= "   /addmemo - เพิ่มบันทึกงานประจำวัน\n";
            $text .= "   /editmemo - แก้ไขบันทึกงานประจำวัน\n";
            $text .= "   /getmemo - เรียกดูบันทึกงานประจำวัน\n";
            $text .= "   /notetoday - เพิ่มหมายเหตุกรณีเป็นวันหยุด หรือวันลา\n\n";
            $text .= "   หากต้องการล้างบันทึก/หมายเหตุประจำวัน สามารถ\n";
            $text .= "   /resetmemo - ล้างบันทึกงานประจำวัน\n";
            $text .= "   /resetnotetoday - ล้างหมายเหตุประจำวัน\n\n";
            $result = app('telegram_bot')->sendMessage($chat_id, $text);
            return response()->json($result, 200);
        }
    }
    public function getUserMemo($telegram_chat_id)
    {
        $currentDate = Carbon::now()->toDateString();
        $userMemo = Memo::where('user_id', $telegram_chat_id)->where('memo_date', $currentDate)->first();
        return $userMemo;
    }
}