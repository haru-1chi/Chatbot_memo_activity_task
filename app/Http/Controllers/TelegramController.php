<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use PhpOffice\PhpWord\TemplateProcessor;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpWord\IOFactory;
use App\Models\User;
use App\Models\Memo;
use Illuminate\Http\Request;
use App\Services\TelegramBot;

class TelegramController extends Controller
{
    protected $telegram_bot_service;

    public function __construct(TelegramBot $telegram_bot_service)
    {
        $this->telegram_bot_service = $telegram_bot_service;
    }
    public function inbound(Request $request)
    {
        Log::channel('null')->info('Skipping logging for inbound message');
        $chat_id = $request->message['from']['id'] ?? null;

        if ($request->message['text'] === '/cancel') {
            Cache::flush();
            // cache()->forget("chat_id_{$chat_id}_start_set_info");
            // cache()->forget("chat_id_{$chat_id}_start_edit_info");
            app('telegram_bot')->sendMessage($chat_id, "ยกเลิกคำสั่งปัจจุบันเรียบร้อยแล้ว");
            return;
        }

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

            $text .= "   /generatedoc - สร้างเอกสารสรุปงานประจำสัปดาห์\n";

            $result = app('telegram_bot')->sendMessage($chat_id, $text);

            return response()->json($result, 200);
        }
        //setinfo
        if ($request->message['text'] === '/setinfo') {
            return $this->setInfoForm($chat_id);
        }

        if (cache()->has("chat_id_{$chat_id}_start_set_info")) {
            $step = cache()->get("chat_id_{$chat_id}_start_set_info");
            if ($step === 'waiting_for_command') {
                return $this->showSetInfoForm($chat_id, $request);
            } elseif ($step === 'confirm') {
                return $this->handleConfirmation(
                    $request,
                    $chat_id,
                    [
                        "chat_id_{$chat_id}_user_info",
                        "chat_id_{$chat_id}_start_set_info"
                    ],
                    'ยกเลิกการ /setinfo',
                    function () use ($chat_id) {
                        $user_info = cache()->get("chat_id_{$chat_id}_user_info");
                        if ($user_info) {
                            $this->saveUserInfo($user_info, $chat_id);
                            app('telegram_bot')->sendMessage($chat_id, "บันทึกข้อมูลเรียบร้อยแล้ว");
                            cache()->forget("chat_id_{$chat_id}_user_info");
                            cache()->forget("chat_id_{$chat_id}_start_set_info");
                        } else {
                            app('telegram_bot')->sendMessage($chat_id, "ไม่พบข้อมูล user");
                        }
                    }
                );
            }
        }
        //editinfo
        if ($request->message['text'] === '/editinfo') {
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {
                $text = "ต้องการแก้ไขข้อมูลใด:\n";
                $text .= "1. ชื่อ-นามสกุล: {$user_info['name']}\n";
                $text .= "2. รหัสนิสิต: {$user_info['student_id']}\n";
                $text .= "3. เบอร์โทรศัพท์: {$user_info['phone_number']}\n";
                $text .= "4. สาขาวิชา: {$user_info['branch']}\n";
                $text .= "5. สถานประกอบการ: {$user_info['company']}\n";
                $text .= "กรุณาตอบเป็นตัวเลข(1-5)";
                cache()->put("chat_id_{$chat_id}_start_edit_info", 'waiting_for_command', now()->addMinutes(60));
                $result = app('telegram_bot')->sendMessage($chat_id, $text);
                return response()->json($result, 200);
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัว";
                $result = app('telegram_bot')->sendMessage($chat_id, $text);
                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_start_edit_info")) {
            $step = cache()->get("chat_id_{$chat_id}_start_edit_info");
            $select = cache()->get("chat_id_{$chat_id}_select_choice_edit");
            $user_info = $this->getUserInfo($chat_id);
            if ($step === 'waiting_for_command') {
                $selected_index = (int) $request->message['text'];
                if ($user_info && is_array($user_info->toArray()) && $selected_index >= 1 && $selected_index <= 5) {
                    $column_name = [
                        1 => 'ชื่อ-นามสกุล',
                        2 => 'รหัสนิสิต',
                        3 => 'เบอร์โทรศัพท์',
                        4 => 'สาขาวิชา',
                        5 => 'สถานประกอบการ'
                    ];
                    $text = "กรุณากรอกข้อมูลดังกล่าวใหม่\n";
                    $text .= "$selected_index. {$column_name[$selected_index]}\n";
                    cache()->put("chat_id_{$chat_id}_start_edit_info", 'updated', now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_select_choice_edit", $selected_index, now()->addMinutes(60));
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);

                    return response()->json($result, 200);
                } else {
                    $text = "กรุณาตอบเป็นตัวเลข(1-5)เท่านั้น";
                    app('telegram_bot')->sendMessage($chat_id, $text);
                }
            } elseif ($step === 'updated') {
                $select = cache()->get("chat_id_{$chat_id}_select_choice_edit");
                $memo_messages = $request->message['text'];
                cache()->put("chat_id_{$chat_id}_edit_user_info", $memo_messages, now()->addMinutes(60));
                $current_memo = cache()->get("chat_id_{$chat_id}_edit_user_info");
                $column_name = [
                    1 => 'ชื่อ-นามสกุล',
                    2 => 'รหัสนิสิต',
                    3 => 'เบอร์โทรศัพท์',
                    4 => 'สาขาวิชา',
                    5 => 'สถานประกอบการ'
                ];
                $text = "ข้อมูลที่แก้ไขใหม่\n";
                $text .= "{$column_name[$select]}: {$current_memo}\n";
                $text .= "ถูกต้องไหมคะ?\n(กรุณาตอบ yes หรือ /cancel)";
                app('telegram_bot')->sendMessage($chat_id, $text);
                cache()->put("chat_id_{$chat_id}_start_edit_info", 'waiting_for_time', now()->addMinutes(60));
            } elseif ($step === 'waiting_for_time') {
                return $this->handleConfirmation(
                    $request,
                    $chat_id,
                    [
                        "chat_id_{$chat_id}_start_edit_reminder",
                        "chat_id_{$chat_id}_edit_reminder",
                        "chat_id_{$chat_id}_select_type_edit"
                    ],
                    'ยกเลิกการ /editinfo',
                    function () use ($chat_id) {
                        $user_info = cache()->get("chat_id_{$chat_id}_select_choice_edit");
                        if ($user_info) {
                            $column_name = [
                                1 => 'name',
                                2 => 'student_id',
                                3 => 'phone_number',
                                4 => 'branch',
                                5 => 'company'
                            ];
                            $text_update = cache()->get("chat_id_{$chat_id}_edit_user_info");
                            User::where('telegram_chat_id', $chat_id)->update([
                                $column_name[$user_info] => $text_update
                            ]);
                        }
                        app('telegram_bot')->sendMessage($chat_id, "แก้ไขข้อมูลเรียบร้อยแล้ว");
                        cache()->forget("chat_id_{$chat_id}_edit_user_info");
                        cache()->forget("chat_id_{$chat_id}_start_edit_info");
                        cache()->forget("chat_id_{$chat_id}_select_choice_edit");
                    }
                );
            }
        }
        //getinfo
        if ($request->message['text'] === '/getinfo') {
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {
                $text = "ข้อมูลส่วนตัวของคุณ:\n";
                $text .= "1. ชื่อ-นามสกุล: {$user_info['name']}\n";
                $text .= "2. รหัสนิสิต: {$user_info['student_id']}\n";
                $text .= "3. เบอร์โทรศัพท์: {$user_info['phone_number']}\n";
                $text .= "4. สาขาวิชา: {$user_info['branch']}\n";
                $text .= "5. สถานประกอบการ: {$user_info['company']}\n";
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
        //setreminder
        if ($request->message['text'] === '/setreminder') {
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {
                return $this->setReminder($request);
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัว\nก่อนทำการแจ้งเตือนใดๆ";
                $result = app('telegram_bot')->sendMessage($chat_id, $text);
                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_start_set_reminder")) {
            $step = cache()->get("chat_id_{$chat_id}_start_set_reminder");
            $select = cache()->get("chat_id_{$chat_id}_select_type");
            if ($step === 'waiting_for_command') {
                $message = $request->message['text'];
                if ($message === '/formemo') {
                    $text = "ต้องการให้แจ้งเตือนจดบันทึกงานประจำวันกี่โมง?\n";
                    $text .= "กรุณาตอบในรูปแบบนาฬิกา 24 ชั่วโมง\n";
                    $text .= "ยกตัวอย่าง <10:00>\n";
                    cache()->put("chat_id_{$chat_id}_start_set_reminder", 'waiting_for_time', now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_select_type", '/formemo', now()->addMinutes(60));
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);

                    return response()->json($result, 200);
                } elseif ($message === '/forsummary') {
                    $text = "ต้องการให้แจ้งเตือนสรุปงานประจำวันกี่โมง?\n";
                    $text .= "กรุณาตอบในรูปแบบนาฬิกา 24 ชั่วโมง\n";
                    $text .= "ยกตัวอย่าง <10:00>\n";
                    cache()->put("chat_id_{$chat_id}_start_set_reminder", 'waiting_for_time', now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_select_type", '/forsummary', now()->addMinutes(60));
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);
                    return response()->json($result, 200);
                } else {
                    $text = "กรุณาเลือกระหว่าง /formemo หรือ /forsummary เท่านั้น";
                    app('telegram_bot')->sendMessage($chat_id, $text);
                }
            } elseif ($step === 'waiting_for_time') {
                if ($select === '/formemo') {
                    $time = $request->message['text'];

                    $text = "ให้แจ้งเตือนเริ่มจดบันทึกงานประจำวันในเวลา\n";
                    $text .= "{$time} น. ใช่ไหมคะ?\n";
                    $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
                    app('telegram_bot')->sendMessage($chat_id, $text);
                    cache()->put("chat_id_{$chat_id}_set_reminder", ['type' => '/formemo', 'time' => $time], now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_start_set_reminder", 'confirm', now()->addMinutes(60));
                    cache()->forget("chat_id_{$chat_id}_select_type");
                }
                if ($select === '/forsummary') {
                    $time = $request->message['text'];

                    $text = "ให้แจ้งเตือนสรุปงานประจำวันในเวลา\n";
                    $text .= "{$time} น. ใช่ไหมคะ?\n";
                    $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
                    app('telegram_bot')->sendMessage($chat_id, $text);
                    cache()->put("chat_id_{$chat_id}_set_reminder", ['type' => '/forsummary', 'time' => $time], now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_start_set_reminder", 'confirm', now()->addMinutes(60));
                    cache()->forget("chat_id_{$chat_id}_select_type");
                }
            } elseif ($step === 'confirm') {
                return $this->handleConfirmation(
                    $request,
                    $chat_id,
                    [
                        "chat_id_{$chat_id}_set_reminder",
                        "chat_id_{$chat_id}_start_set_reminder"
                    ],
                    'ยกเลิกการ /setreminder',
                    function () use ($chat_id) {
                        $text_reply = '';
                        $set_reminder_time = cache()->get("chat_id_{$chat_id}_set_reminder");
                        if ($set_reminder_time) {
                            switch ($set_reminder_time['type']) {
                                case '/formemo':
                                    User::where('telegram_chat_id', $chat_id)->update([
                                        'memo_time' => $set_reminder_time['time'],
                                    ]);
                                    $text_reply = "ตั้งค่าเวลาแจ้งเตือนเริ่มจดบันทึกงานประจำวันเรียบร้อยแล้ว!";
                                    break;
                                case '/forsummary':
                                    User::where('telegram_chat_id', $chat_id)->update([
                                        'summary_time' => $set_reminder_time['time'],
                                    ]);
                                    $text_reply = "ตั้งค่าเวลาแจ้งเตือนสรุปงานประจำวันเรียบร้อยแล้ว!";
                                    break;
                                default:
                                    break;
                            }
                            app('telegram_bot')->sendMessage($chat_id, $text_reply);
                            cache()->forget("chat_id_{$chat_id}_set_reminder");
                            cache()->forget("chat_id_{$chat_id}_start_set_reminder");
                        } else {
                            app('telegram_bot')->sendMessage($chat_id, "ไม่พบข้อมูล user");
                        }
                    }
                );

                // return $this->handleReminderConfirmation($request);
            }

        }
        //editreminder
        if ($request->message['text'] === '/editreminder') {
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {
                return $this->editReminder($request);
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัว\nก่อนทำการแจ้งเตือนใดๆ";
                $result = app('telegram_bot')->sendMessage($chat_id, $text);
                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_start_edit_reminder")) {
            $step = cache()->get("chat_id_{$chat_id}_start_edit_reminder");
            $select = cache()->get("chat_id_{$chat_id}_select_type_edit");
            if ($step === 'waiting_for_command') {
                $message = $request->message['text'];
                if ($message === '1') {
                    $text = "ต้องการแก้ไขเวลาแจ้งเตือนจดบันทึกงานประจำวันกี่โมง?\n";
                    $text .= "กรุณาตอบในรูปแบบนาฬิกา 24 ชั่วโมง\n";
                    $text .= "ยกตัวอย่าง <10:00>\n";
                    cache()->put("chat_id_{$chat_id}_start_edit_reminder", 'waiting_for_time', now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_select_type_edit", '/formemo', now()->addMinutes(60));
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);

                    return response()->json($result, 200);
                } elseif ($message === '2') {
                    $text = "ต้องการแก้ไขเวลาสรุปงานประจำวันกี่โมง?\n";
                    $text .= "กรุณาตอบในรูปแบบนาฬิกา 24 ชั่วโมง\n";
                    $text .= "ยกตัวอย่าง <10:00>\n";
                    cache()->put("chat_id_{$chat_id}_start_edit_reminder", 'waiting_for_time', now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_select_type_edit", '/forsummary', now()->addMinutes(60));
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);

                    return response()->json($result, 200);
                } else {
                    $text = "กรุณาตอบเป็นตัวเลข 1 หรือ 2 เท่านั้น";
                    app('telegram_bot')->sendMessage($chat_id, $text);
                }
            } elseif ($step === 'waiting_for_time') {
                if ($select === '/formemo') {
                    $time = $request->message['text'];
                    $text = "ให้แจ้งเตือนเริ่มจดบันทึกงานประจำวันในเวลา\n";
                    $text .= "{$time} น. ใช่ไหมคะ?\n";
                    $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
                    app('telegram_bot')->sendMessage($chat_id, $text);
                    cache()->put("chat_id_{$chat_id}_edit_reminder", ['type' => '/formemo', 'time' => $time], now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_start_edit_reminder", 'confirm', now()->addMinutes(60));
                    cache()->forget("chat_id_{$chat_id}_select_type_edit");
                }
                if ($select === '/forsummary') {
                    $time = $request->message['text'];

                    $text = "ให้แจ้งเตือนสรุปงานประจำวันในเวลา\n";
                    $text .= "{$time} น. ใช่ไหมคะ?\n";
                    $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
                    app('telegram_bot')->sendMessage($chat_id, $text);
                    cache()->put("chat_id_{$chat_id}_edit_reminder", ['type' => '/forsummary', 'time' => $time], now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_start_edit_reminder", 'confirm', now()->addMinutes(60));
                    cache()->forget("chat_id_{$chat_id}_select_type_edit");
                }
            } elseif ($step === 'confirm') {
                return $this->handleConfirmation(
                    $request,
                    $chat_id,
                    [
                        "chat_id_{$chat_id}_start_edit_reminder",
                        "chat_id_{$chat_id}_edit_reminder"
                    ],
                    'ยกเลิกการ /editreminder',
                    function () use ($chat_id) {
                        $text_reply = '';
                        $set_reminder_time = cache()->get("chat_id_{$chat_id}_edit_reminder");
                        if ($set_reminder_time) {
                            switch ($set_reminder_time['type']) {
                                case '/formemo':
                                    User::where('telegram_chat_id', $chat_id)->update([
                                        'memo_time' => $set_reminder_time['time'],
                                    ]);
                                    $text_reply = "แก้ไขเวลาแจ้งเตือนเริ่มจดบันทึกงานประจำวันเรียบร้อยแล้ว!";
                                    break;
                                case '/forsummary':
                                    User::where('telegram_chat_id', $chat_id)->update([
                                        'summary_time' => $set_reminder_time['time'],
                                    ]);
                                    $text_reply = "แก้ไขเวลาแจ้งเตือนสรุปงานประจำวันเรียบร้อยแล้ว!";
                                    break;
                                default:
                                    break;
                            }
                            app('telegram_bot')->sendMessage($chat_id, $text_reply);
                            cache()->forget("chat_id_{$chat_id}_start_edit_reminder");
                            cache()->forget("chat_id_{$chat_id}_edit_reminder");
                        } else {
                            app('telegram_bot')->sendMessage($chat_id, "ไม่พบข้อมูล user");
                        }
                    }
                );

                // return $this->handleEditReminderConfirmation($request);
            }
        }
        //getreminder
        if ($request->message['text'] === '/getreminder') {
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {
                if (!empty($user_info['memo_time'] && $user_info['summary_time'])) {
                    $memo_time = Carbon::createFromFormat('H:i:s', $user_info['memo_time'])->format('H:i');
                    $summary_time = Carbon::createFromFormat('H:i:s', $user_info['summary_time'])->format('H:i');
                    $text = "แจ้งเตือนการจดบันทึกประจำวันเวลา: {$memo_time} น.\n";
                    $text .= "แจ้งเตือนสรุปงานประจำวันเวลา: {$summary_time} น.\n";
                    $text .= "หากต้องการแก้ไข สามารถ /editreminder";
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);
                    return response()->json($result, 200);
                } elseif (!empty($user_info['memo_time']) && empty($user_info['summary_time'])) {
                    $memo_time = Carbon::createFromFormat('H:i:s', $user_info['memo_time'])->format('H:i');
                    $text = "แจ้งเตือนการจดบันทึกประจำวันเวลา: {$memo_time} น.\n";
                    $text .= "คุณยังไม่ได้สรุปงานประจำวัน!\n";
                    $text .= "กรุณา /setreminder เพื่อตั้งค่าเวลาสรุปงานประจำวัน";
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);
                    return response()->json($result, 200);
                } elseif (empty($user_info['memo_time']) && !empty($user_info['summary_time'])) {
                    $summary_time = Carbon::createFromFormat('H:i:s', $user_info['summary_time'])->format('H:i');
                    $text = "คุณยังไม่ได้ตั้งค่าเวลาแจ้งเตือนจดบันทึกประจำวัน!\n";
                    $text .= "แจ้งเตือนสรุปงานประจำวันเวลา: {$summary_time} น.\n";
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
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {
                return $this->memoDairy($request);
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัวก่อนทำการจดบันทึกใดๆ";
                $result = app('telegram_bot')->sendMessage($chat_id, $text);
                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_start_memo_dairy")) {
            $step = cache()->get("chat_id_{$chat_id}_start_memo_dairy");
            if ($step === 'waiting_for_command') {
                $memo_message = $request->message['text'];
                if ($memo_message === '/end') {
                    $current_memo = cache()->get("chat_id_{$chat_id}_memo_daily"); //case null
                    if ($current_memo !== null && !empty($current_memo)) {
                        $formatted_memo = [];
                        foreach ($current_memo as $key => $memo) {
                            $formatted_memo[] = ($key + 1) . ". " . $memo;
                        }
                        $text = "งานที่บันทึกในตอนนี้:\n" . implode("\n", $formatted_memo);
                        $text .= "\nถูกต้องมั้ยคะ? (กรุณาตอบ yes หรือ /cancel)\n";
                        app('telegram_bot')->sendMessage($chat_id, $text);
                        cache()->put("chat_id_{$chat_id}_start_memo_dairy", 'waiting_for_time', now()->addMinutes(60));
                    } else {
                        $text = "\nกรุณาเพิ่มบันทึกประจำวันใหม่อีกครั้ง\nเมื่อจดบันทึกครบแล้ว ให้พิมพ์ /end เพื่อจบการบันทึก";
                        app('telegram_bot')->sendMessage($chat_id, $text);
                        cache()->put("chat_id_{$chat_id}_start_memo_dairy", 'waiting_for_command', now()->addMinutes(60));
                    }
                } else {
                    $memo_messages = cache()->get("chat_id_{$chat_id}_memo_daily", []);
                    $memo_messages[] = $memo_message;
                    cache()->put("chat_id_{$chat_id}_memo_daily", $memo_messages, now()->addMinutes(60));
                }
            } elseif ($step === 'waiting_for_time') {
                return $this->handleConfirmation(
                    $request,
                    $chat_id,
                    [
                        "chat_id_{$chat_id}_start_memo_dairy",
                        "chat_id_{$chat_id}_memo_daily"
                    ],
                    'ยกเลิกการ /memo',
                    function () use ($chat_id) {
                        $text_reply = '';
                        $current_memo = cache()->get("chat_id_{$chat_id}_memo_daily");
                        $current_time = Carbon::now()->toDateString();
                        if ($current_memo && Memo::where('user_id', $chat_id)->whereDate('memo_date', $current_time)->exists()) {
                            $formatted_memo = implode(', ', $current_memo);
                            Memo::where('user_id', $chat_id)->where('memo_date', $current_time)->update(['memo' => $formatted_memo]);
                            $text_reply = "บันทึกงานประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                        } elseif ($current_memo) {
                            $formatted_memo = implode(', ', $current_memo);
                            Memo::create(['user_id' => $chat_id, 'memo' => $formatted_memo, 'memo_date' => $current_time]);

                            $text_reply = "บันทึกงานประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                        } else {
                            $text_reply = "ไม่มีงานประจำวันที่จะบันทึกในขณะนี้ค่ะ!";
                        }

                        app('telegram_bot')->sendMessage($chat_id, $text_reply);
                        cache()->forget("chat_id_{$chat_id}_start_memo_dairy");
                        cache()->forget("chat_id_{$chat_id}_memo_daily");
                    }
                );
                // $confirmation_text = 'yes';
                // $text_reply = '';
                // $text = $request->message['text'];
                // if ($text === $confirmation_text) {
                //     $current_memo = cache()->get("chat_id_{$chat_id}_memo_daily");
                //     $current_time = Carbon::now()->toDateString();
                //     if ($current_memo && Memo::where('user_id', $chat_id)->whereDate('memo_date', $current_time)->exists()) {
                //         $formatted_memo = implode(', ', $current_memo);
                //         Memo::where('user_id', $chat_id)->where('memo_date', $current_time)->update(['memo' => $formatted_memo]);
                //         $text_reply = "บันทึกงานประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                //     } elseif ($current_memo) {
                //         $formatted_memo = implode(', ', $current_memo);
                //         Memo::create(['user_id' => $chat_id, 'memo' => $formatted_memo, 'memo_date' => $current_time]);

                //         $text_reply = "บันทึกงานประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                //     } else {
                //         $text_reply = "ไม่มีงานประจำวันที่จะบันทึกในขณะนี้ค่ะ!";
                //     }

                //     app('telegram_bot')->sendMessage($chat_id, $text_reply);
                //     cache()->forget("chat_id_{$chat_id}_start_memo_dairy");
                //     cache()->forget("chat_id_{$chat_id}_memo_daily");
                // } elseif ($text === '/cancel') {
                //     app('telegram_bot')->sendMessage($chat_id, "ยกเลิกการ /memo");
                //     cache()->forget("chat_id_{$chat_id}_start_memo_dairy");
                //     cache()->forget("chat_id_{$chat_id}_memo_daily");
                // } else {
                //     app('telegram_bot')->sendMessage($chat_id, "/memo กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ");
                // }
            }
        }
        //getmemo
        if ($request->message['text'] === '/getmemo') {
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {

                $user_memo = $this->getUserMemo($chat_id);
                if (!$user_memo || (!$user_memo['memo'] && !$user_memo['note_today'])) {

                    $text = "คุณยังไม่ได้จดบันทึกงานประจำวัน!\n";
                    $text .= "กรุณา /memo เพื่อเริ่มจดบันทึกประจำวัน";
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);

                    return response()->json($result, 200);
                } elseif ($user_memo['memo']) {

                    $memo_array = explode(', ', $user_memo['memo']);
                    $formatted_memo = [];
                    foreach ($memo_array as $key => $memo) {
                        $formatted_memo[] = ($key + 1) . ". " . $memo;
                    }
                    $text = "งานที่บันทึกในตอนนี้:\n" . implode("\n", $formatted_memo);
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
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);
                    return response()->json($result, 200);
                } elseif ($user_memo['note_today'] && empty($user_memo['memo'])) {
                    $text = "หมายเหตุประจำวัน:\n{$user_memo['note_today']}";
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
        //addmemo
        if ($request->message['text'] === '/addmemo') {
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {
                return $this->addMemoDairy($request);
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัวก่อนทำการจดบันทึกใดๆ";
                $result = app('telegram_bot')->sendMessage($chat_id, $text);
                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_start_add_memo_dairy")) {
            $step = cache()->get("chat_id_{$chat_id}_start_add_memo_dairy");
            if ($step === 'waiting_for_command') {
                $memo_message = $request->message['text'];
                $user_memo = $this->getUserMemo($chat_id);
                $memo_array = explode(', ', $user_memo['memo']);
                if ($memo_message === '/end') {
                    $current_memo = cache()->get("chat_id_{$chat_id}_add_memo_daily"); //case null
                    if ($current_memo !== null) {
                        $formatted_memo = [];
                        foreach ($current_memo as $key => $memo) {
                            $formatted_memo[] = ($key + 1) . ". " . $memo;
                        }
                        $text = "งานที่บันทึกในตอนนี้:\n" . implode("\n", $formatted_memo);
                        $text .= "\nถูกต้องมั้ยคะ? (กรุณาตอบ yes หรือ /cancel)\n";
                        app('telegram_bot')->sendMessage($chat_id, $text);
                        cache()->put("chat_id_{$chat_id}_start_add_memo_dairy", 'waiting_for_time', now()->addMinutes(60));
                    } else {
                        $text = "\nกรุณาเพิ่มบันทึกประจำวันใหม่อีกครั้ง\nเมื่อจดบันทึกครบแล้ว ให้พิมพ์ /end เพื่อจบการบันทึก";
                        app('telegram_bot')->sendMessage($chat_id, $text);
                        cache()->put("chat_id_{$chat_id}_start_add_memo_dairy", 'waiting_for_command', now()->addMinutes(60));
                    }
                } else {
                    $memo_array = cache()->get("chat_id_{$chat_id}_add_memo_daily", $memo_array);
                    $memo_array[] = $memo_message;
                    cache()->put("chat_id_{$chat_id}_add_memo_daily", $memo_array, now()->addMinutes(60));
                }
            } elseif ($step === 'waiting_for_time') {
                return $this->handleConfirmation(
                    $request,
                    $chat_id,
                    [
                        "chat_id_{$chat_id}_start_add_memo_dairy",
                        "chat_id_{$chat_id}_add_memo_daily"
                    ],
                    'ยกเลิกการ /addmemo',
                    function () use ($chat_id) {
                        $text_reply = '';
                        $current_memo = cache()->get("chat_id_{$chat_id}_add_memo_daily");

                        if (!empty($current_memo)) {
                            $formatted_memo = implode(', ', $current_memo);
                            $current_date = Carbon::now()->toDateString();
                            Memo::where('user_id', $chat_id)->where('memo_date', $current_date)->update(['memo' => $formatted_memo,]);
                            $text_reply = "บันทึกงานประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                        } else {
                            $text_reply = "ไม่มีงานประจำวันที่จะบันทึกในขณะนี้ค่ะ!";
                        }

                        app('telegram_bot')->sendMessage($chat_id, $text_reply);
                        cache()->forget("chat_id_{$chat_id}_start_add_memo_dairy");
                        cache()->forget("chat_id_{$chat_id}_add_memo_daily");
                    }
                );

                // $confirmation_text = 'yes';
                // $text_reply = '';
                // $text = $request->message['text'];
                // if ($text === $confirmation_text) {
                //     $current_memo = cache()->get("chat_id_{$chat_id}_add_memo_daily");

                //     if (!empty($current_memo)) {
                //         $formatted_memo = implode(', ', $current_memo);
                //         $current_date = Carbon::now()->toDateString();
                //         Memo::where('user_id', $chat_id)->where('memo_date', $current_date)->update(['memo' => $formatted_memo,]);
                //         $text_reply = "บันทึกงานประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                //     } else {
                //         $text_reply = "ไม่มีงานประจำวันที่จะบันทึกในขณะนี้ค่ะ!";
                //     }

                //     app('telegram_bot')->sendMessage($chat_id, $text_reply);
                //     cache()->forget("chat_id_{$chat_id}_start_add_memo_dairy");
                //     cache()->forget("chat_id_{$chat_id}_add_memo_daily");
                // } elseif ($text === '/cancel') {
                //     app('telegram_bot')->sendMessage($chat_id, "ยกเลิกการ /addmemo");
                //     cache()->forget("chat_id_{$chat_id}_start_add_memo_dairy");
                //     cache()->forget("chat_id_{$chat_id}_add_memo_daily");
                // } else {
                //     app('telegram_bot')->sendMessage($chat_id, "/addmemo กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ");
                // }

            }
        }
        //editmemo
        if ($request->message['text'] === '/editmemo') {
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {
                return $this->editMemoDairy($request);
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัวก่อนทำการจดบันทึกใดๆ";
                $result = app('telegram_bot')->sendMessage($chat_id, $text);
                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_start_edit_memo_dairy")) {
            $step = cache()->get("chat_id_{$chat_id}_start_edit_memo_dairy");
            $select = cache()->get("chat_id_{$chat_id}_select_choice_edit_memo");
            $user_memo = $this->getUserMemo($chat_id);
            $memo_messages = explode(', ', $user_memo['memo']);

            if ($step === 'waiting_for_command') {
                $selected_index = $request->message['text'];
                if ($selected_index >= 1 && $selected_index <= count($memo_messages)) {
                    $text = "สามารถพิมพ์ข้อความเพื่อแก้ไขงานประจำวันได้เลยค่ะ\n";
                    $text .= "(สามารถแก้ไขได้เพียงข้อที่เลือก)\n";
                    $text .= "'Create function CRUD'\n";
                    cache()->put("chat_id_{$chat_id}_start_edit_memo_dairy", 'updated', now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_select_choice_edit_memo", $selected_index, now()->addMinutes(60));
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);

                    return response()->json($result, 200);
                } else {
                    $number_of_memo_massages = count($memo_messages);
                    $text = "กรุณาตอบเป็นตัวเลข 1-$number_of_memo_massages เท่านั้น";
                    app('telegram_bot')->sendMessage($chat_id, $text);
                }
            } elseif ($step === 'updated') {
                $select = cache()->get("chat_id_{$chat_id}_select_choice_edit_memo");
                $memo_messages[$select - 1] = $request->message['text'];
                cache()->put("chat_id_{$chat_id}_edit_memo_dairy", $memo_messages, now()->addMinutes(60));
                $current_memo = cache()->get("chat_id_{$chat_id}_edit_memo_dairy");
                $formatted_memo = [];
                foreach ($current_memo as $key => $memo) {
                    $formatted_memo[] = ($key + 1) . ". " . $memo;
                }
                $text = "งานที่บันทึกในตอนนี้:\n" . implode("\n", $formatted_memo);
                $text .= "\nถูกต้องมั้ยคะ? (กรุณาตอบ yes หรือ /cancel)\n";
                app('telegram_bot')->sendMessage($chat_id, $text);
                cache()->put("chat_id_{$chat_id}_start_edit_memo_dairy", 'waiting_for_time', now()->addMinutes(60));
            } elseif ($step === 'waiting_for_time') {
                return $this->handleConfirmation(
                    $request,
                    $chat_id,
                    [
                        "chat_id_{$chat_id}_edit_memo_dairy",
                        "chat_id_{$chat_id}_start_edit_memo_dairy",
                        "chat_id_{$chat_id}_select_choice_edit_memo"
                    ],
                    'ยกเลิกการ /editmemo',
                    function () use ($chat_id) {
                        $text_reply = '';
                        $current_memo = cache()->get("chat_id_{$chat_id}_edit_memo_dairy");
                        if (!empty($current_memo)) {
                            $formatted_memo = implode(', ', $current_memo);
                            $current_date = Carbon::now()->toDateString();
                            Memo::where('user_id', $chat_id)->where('memo_date', $current_date)->update(['memo' => $formatted_memo]);
                            $text_reply = "บันทึกงานประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                        } else {
                            $text_reply = "ไม่มีงานประจำวันที่จะบันทึกในขณะนี้ค่ะ!";
                        }
                        app('telegram_bot')->sendMessage($chat_id, $text_reply);
                        cache()->forget("chat_id_{$chat_id}_edit_memo_dairy");
                        cache()->forget("chat_id_{$chat_id}_start_edit_memo_dairy");
                        cache()->forget("chat_id_{$chat_id}_select_choice_edit_memo");
                    }
                );

                // $confirmation_text = 'yes';
                // $text_reply = '';
                // $text = $request->message['text'];
                // if ($text === $confirmation_text) {
                //     $current_memo = cache()->get("chat_id_{$chat_id}_edit_memo_dairy");

                //     if (!empty($current_memo)) {
                //         $formatted_memo = implode(', ', $current_memo);
                //         $current_date = Carbon::now()->toDateString();
                //         Memo::where('user_id', $chat_id)->where('memo_date', $current_date)->update(['memo' => $formatted_memo]);
                //         $text_reply = "บันทึกงานประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                //     } else {
                //         $text_reply = "ไม่มีงานประจำวันที่จะบันทึกในขณะนี้ค่ะ!";
                //     }

                //     app('telegram_bot')->sendMessage($chat_id, $text_reply);
                //     cache()->forget("chat_id_{$chat_id}_edit_memo_dairy");
                //     cache()->forget("chat_id_{$chat_id}_start_edit_memo_dairy");
                //     cache()->forget("chat_id_{$chat_id}_select_choice_edit_memo");
                // } elseif ($text === '/cancel') {
                //     app('telegram_bot')->sendMessage($chat_id, "ยกเลิกการ /editmemo");
                //     cache()->forget("chat_id_{$chat_id}_edit_memo_dairy");
                //     cache()->forget("chat_id_{$chat_id}_start_edit_memo_dairy");
                //     cache()->forget("chat_id_{$chat_id}_select_choice_edit_memo");
                // } else {
                //     app('telegram_bot')->sendMessage($chat_id, "/editmemo กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ");
                // }

            }
        }
        //resetmemo
        if ($request->message['text'] === '/resetmemo') {
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {
                $user_memo = $this->getUserMemo($chat_id);
                if (!$user_memo || !$user_memo['memo'] || (!$user_memo['memo'] && !$user_memo['note_today'])) {
                    $text = "คุณยังไม่ได้จดบันทึกงานประจำวัน!\n";
                    $text .= "กรุณา /memo เพื่อเริ่มจดบันทึกประจำวัน";
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);
                    return response()->json($result, 200);
                } else {
                    $memo_array = explode(', ', $user_memo['memo']);
                    $formatted_memo = [];
                    foreach ($memo_array as $key => $memo) {
                        $formatted_memo[] = ($key + 1) . ". " . $memo;
                    }
                    $text = "งานที่บันทึกในตอนนี้:\n" . implode("\n", $formatted_memo);
                    $text .= "\nคุณต้องการล้างบันทึกประจำวันเพื่อเริ่มจดบันทึกใหม่หรือไม่?";
                    $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
                    cache()->put("chat_id_{$chat_id}_start_reset_memo_dairy", true, now()->addMinutes(60));
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

        if (cache()->has("chat_id_{$chat_id}_start_reset_memo_dairy")) {
            return $this->handleConfirmation(
                $request,
                $chat_id,
                [
                    "chat_id_{$chat_id}_start_reset_memo_dairy"
                ],
                'ยกเลิกการ /resetmemo',
                function () use ($chat_id) {
                    $text_reply = '';
                    $user_memo = $this->getUserMemo($chat_id);
                    $user_memo->memo = null;
                    $user_memo->save();
                    $text_reply = "ล้างบันทึกงานประจำวันเรียบร้อยแล้ว!\n";
                    $text_reply .= "สามารถ /memo เพื่อเริ่มจดบันทึกประจำวันใหม่อีกครั้ง";
                    app('telegram_bot')->sendMessage($chat_id, $text_reply);
                    cache()->forget("chat_id_{$chat_id}_start_reset_memo_dairy");
                }
            );

            // $confirmation_text = 'yes';
            // $text_reply = '';
            // $text = $request->message['text'];
            // $user_memo = $this->getUserMemo($chat_id);
            // if ($text === $confirmation_text) {
            //     $user_memo->memo = null;
            //     $user_memo->save();
            //     $text_reply = "ล้างบันทึกงานประจำวันเรียบร้อยแล้ว!\n";
            //     $text_reply .= "สามารถ /memo เพื่อเริ่มจดบันทึกประจำวันใหม่อีกครั้ง";
            //     app('telegram_bot')->sendMessage($chat_id, $text_reply);
            //     cache()->forget("chat_id_{$chat_id}_start_reset_memo_dairy");
            // } elseif ($text === '/cancel') {
            //     app('telegram_bot')->sendMessage($chat_id, "ยกเลิกการ /resetmemo");
            //     cache()->forget("chat_id_{$chat_id}_start_reset_memo_dairy");
            // } else {
            //     app('telegram_bot')->sendMessage($chat_id, "/resetmemo กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ");
            // }

        }
        //resetnotetoday
        if ($request->message['text'] === '/resetnotetoday') {
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {
                $user_memo = $this->getUserMemo($chat_id);
                if ($user_memo['note_today']) {
                    $text = "หมายเหตุประจำวันตอนนี้:\n{$user_memo['note_today']}";
                    $text .= "\nคุณต้องการล้างหมายเหตุประจำวันเพื่อเริ่มจดบันทึกใหม่หรือไม่?";
                    $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
                    cache()->put("chat_id_{$chat_id}_start_reset_notetoday", true, now()->addMinutes(60));
                    $result = app('telegram_bot')->sendMessage($chat_id, $text);
                    return response()->json($result, 200);
                } elseif (!$user_memo['note_today']) {
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

        if (cache()->has("chat_id_{$chat_id}_start_reset_notetoday")) {
            return $this->handleConfirmation(
                $request,
                $chat_id,
                [
                    "chat_id_{$chat_id}_start_reset_notetoday"
                ],
                'ยกเลิกการ /resetnotetoday',
                function () use ($chat_id) {
                    $text_reply = '';
                    $user_memo = $this->getUserMemo($chat_id);
                    $user_memo->note_today = null;
                    $user_memo->save();
                    $text_reply = "ล้างหมายเหตุประจำวันเรียบร้อยแล้ว!\n";
                    $text_reply .= "สามารถ /notetoday เพื่อเริ่มจดบันทึกประจำวันใหม่อีกครั้ง";
                    app('telegram_bot')->sendMessage($chat_id, $text_reply);
                    cache()->forget("chat_id_{$chat_id}_start_reset_notetoday");
                }
            );

            // $confirmation_text = 'yes';
            // $text_reply = '';
            // $text = $request->message['text'];
            // $user_memo = $this->getUserMemo($chat_id);
            // if ($text === $confirmation_text) {
            //     $user_memo->note_today = null;
            //     $user_memo->save();
            //     $text_reply = "ล้างหมายเหตุประจำวันเรียบร้อยแล้ว!\n";
            //     $text_reply .= "สามารถ /notetoday เพื่อเริ่มจดบันทึกประจำวันใหม่อีกครั้ง";
            //     app('telegram_bot')->sendMessage($chat_id, $text_reply);
            //     cache()->forget("chat_id_{$chat_id}_start_reset_notetoday");
            // } elseif ($text === '/cancel') {
            //     app('telegram_bot')->sendMessage($chat_id, "ยกเลิกการ /resetnotetoday");
            //     cache()->forget("chat_id_{$chat_id}_start_reset_notetoday");
            // } else {
            //     app('telegram_bot')->sendMessage($chat_id, "/resetnote กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ");
            // }

        }
        //notetoday
        if ($request->message['text'] === '/notetoday') {
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {
                $user_memo = $this->getUserMemo($chat_id);
                if (!$user_memo || !$user_memo['note_today']) {
                    $text = "สามารถพิมพ์ข้อความใดๆเพื่อเพิ่มหมายเหตุได้เลยค่ะ\n";
                    $text .= "ยกตัวอย่าง ‘วันหยุดปีใหม่’\n";
                    cache()->put("chat_id_{$chat_id}_start_notetoday", 'waiting_for_command', now()->addMinutes(60));
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

        if (cache()->has("chat_id_{$chat_id}_start_notetoday")) {
            $step = cache()->get("chat_id_{$chat_id}_start_notetoday");
            if ($step === 'waiting_for_command') {
                $note_today = $request->message['text'];

                $text = "หมายเหตุของวันนี้:\n";
                $text .= "{$note_today}\nถูกต้องมั้ยคะ?";
                $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
                cache()->put("chat_id_{$chat_id}_start_notetoday", 'confirm', now()->addMinutes(60));
                cache()->put("chat_id_{$chat_id}_notetoday", $note_today, now()->addMinutes(60));
                $result = app('telegram_bot')->sendMessage($chat_id, $text);

            } elseif ($step === 'confirm') {
                return $this->handleConfirmation(
                    $request,
                    $chat_id,
                    [
                        "chat_id_{$chat_id}_start_notetoday",
                        "chat_id_{$chat_id}_notetoday"
                    ],
                    'ยกเลิกการ /notetoday',
                    function () use ($chat_id) {
                        $text_reply = '';
                        $current_notetoday = cache()->get("chat_id_{$chat_id}_notetoday");
                        $current_time = Carbon::now()->toDateString();

                        if ($current_notetoday && Memo::where('user_id', $chat_id)->whereDate('memo_date', $current_time)->exists()) {
                            Memo::where('user_id', $chat_id)->where('memo_date', $current_time)->update(['note_today' => $current_notetoday]);
                            $text_reply = "บันทึกหมายเหตุประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                        } elseif ($current_notetoday) {
                            Memo::create(['user_id' => $chat_id, 'note_today' => $current_notetoday, 'memo_date' => $current_time]);
                            $text_reply = "บันทึกหมายเหตุประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                        } else {
                            $text_reply = "ไม่มีหมายเหตุประจำวันที่จะบันทึกในขณะนี้ค่ะ!";
                        }
                        app('telegram_bot')->sendMessage($chat_id, $text_reply);
                        cache()->forget("chat_id_{$chat_id}_start_notetoday");
                        cache()->forget("chat_id_{$chat_id}_notetoday");
                    }
                );

                // $confirmation_text = 'yes';
                // $text_reply = '';
                // $text = $request->message['text'];
                // if ($text === $confirmation_text) {
                //     $current_notetoday = cache()->get("chat_id_{$chat_id}_notetoday");
                //     $current_time = Carbon::now()->toDateString();

                //     if ($current_notetoday && Memo::where('user_id', $chat_id)->whereDate('memo_date', $current_time)->exists()) {
                //         Memo::where('user_id', $chat_id)->where('memo_date', $current_time)->update(['note_today' => $current_notetoday]);
                //         $text_reply = "บันทึกหมายเหตุประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                //     } elseif ($current_notetoday) {
                //         Memo::create(['user_id' => $chat_id, 'note_today' => $current_notetoday, 'memo_date' => $current_time]);
                //         $text_reply = "บันทึกหมายเหตุประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                //     } else {
                //         $text_reply = "ไม่มีหมายเหตุประจำวันที่จะบันทึกในขณะนี้ค่ะ!";
                //     }
                //     app('telegram_bot')->sendMessage($chat_id, $text_reply);
                //     cache()->forget("chat_id_{$chat_id}_start_notetoday");
                //     cache()->forget("chat_id_{$chat_id}_notetoday");
                // } elseif ($text === '/cancel') {
                //     app('telegram_bot')->sendMessage($chat_id, "ยกเลิกการ /notetoday");
                //     cache()->forget("chat_id_{$chat_id}_start_notetoday");
                //     cache()->forget("chat_id_{$chat_id}_notetoday");
                // } else {
                //     app('telegram_bot')->sendMessage($chat_id, "/notetoday กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ");
                // }

            }
        }
        //generatedoc
        if ($request->message['text'] === '/generatedoc') {
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {
                $user_memo = Memo::where('user_id', $chat_id)->first();
                if ($user_memo) {
                    $word_path = $this->generateWord($request);
                    // $pdf_path = $this->generatePDF($request);
                    app('telegram_bot')->sendDocument($chat_id, $word_path);
                    app('telegram_bot')->sendMessage($chat_id, 'อย่าลืมดาวน์โหลดไฟล์แล้วส่งให้พนักงานที่ปรึกษาลงนามในทุกสัปดาห์ด้วยนะ');
                    // app('telegram_bot')->sendDocument($chat_id, $pdf_path);
                } else {
                    $text = "คุณยังไม่ได้จดบันทึกประจำวันใดๆเลย\n";
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
    }

    public function generatePDF(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $user_info = $this->getUserInfo($chat_id);
        $directory = 'word-send';
        if (!file_exists(public_path($directory))) {
            mkdir(public_path($directory), 0777, true);
        }
        $template_processor = new TemplateProcessor('word-template/user.docx');
        $memo_dates = Memo::where('user_id', $chat_id)
            ->pluck('memo_date')
            ->unique();
        $current_week_number = $memo_dates->map(function ($date) {
            return Carbon::parse($date)->weekOfYear;
        })->unique()->count();
        $latest_week_memos = Memo::where('user_id', $chat_id)
            ->whereBetween('memo_date', [
                Carbon::now()->startOfWeek()->format('Y-m-d'),
                Carbon::now()->endOfWeek()->format('Y-m-d')
            ])
            ->orderBy('memo_date')
            ->get();
        $latest_week_memos_indexed = [];
        foreach ($latest_week_memos as $memo) {
            $weekday_index = Carbon::parse($memo->memo_date)->dayOfWeekIso;
            $latest_week_memos_indexed[$weekday_index] = $memo;
        }

        for ($i = 1; $i <= 7; $i++) {
            if (!isset($latest_week_memos_indexed[$i])) {
                $template_processor->setValue("memo_date_$i", '');
                for ($j = 0; $j < 5; $j++) {
                    $template_processor->setValue("memo[$j]_$i", '……………………………………………………………………………………');
                }
                $template_processor->setValue("note_today_$i", '');
            } else {
                $memo = $latest_week_memos_indexed[$i];
                $template_processor->setValue("number_of_week", $current_week_number);
                $template_processor->setValue("memo_date_$i", $memo->memo_date);
                for ($j = 0; $j < 5; $j++) {
                    $template_processor->setValue("memo[$j]_$i", $this->getMemo($memo->memo, $j));
                }
                $template_processor->setValue("note_today_$i", $memo->note_today);
            }
        }
        $file_name = $user_info['student_id'] . '_week1_memo.docx';
        $file_path = public_path($directory . DIRECTORY_SEPARATOR . $file_name);
        $template_processor->saveAs($file_path);

        $php_word = IOFactory::load($file_path);
        $html_writer = IOFactory::createWriter($php_word, 'HTML');
        $html_file_path = public_path($directory . DIRECTORY_SEPARATOR . 'temp.html');
        $html_writer->save($html_file_path);

        $dompdf = new Dompdf();
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $dompdf->setOptions($options);

        $html_content = file_get_contents($html_file_path);
        $dompdf->loadHtml($html_content);

        $dompdf->setPaper('A4', 'portrait');

        $dompdf->render();

        $pdf_file_path = public_path($directory . DIRECTORY_SEPARATOR . 'output.pdf');
        file_put_contents($pdf_file_path, $dompdf->output());

        unlink($file_path);
        unlink($html_file_path);

        return $pdf_file_path;
    }
    public function generateWord(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $user_info = $this->getUserInfo($chat_id);
        $directory = 'word-send';
        if (!file_exists(public_path($directory))) {
            mkdir(public_path($directory), 0777, true);
        }
        $template_processor = new TemplateProcessor('word-template/user.docx');
        $memo_dates = Memo::where('user_id', $chat_id)
            ->pluck('memo_date')
            ->unique();
        $current_week_number = $memo_dates->map(function ($date) {
            return Carbon::parse($date)->weekOfYear;
        })->unique()->count();
        $latest_week_memos = Memo::where('user_id', $chat_id)
            ->whereBetween('memo_date', [
                Carbon::now()->startOfWeek()->format('Y-m-d'),
                Carbon::now()->endOfWeek()->format('Y-m-d')
            ])
            ->orderBy('memo_date')
            ->get();
        $latest_week_memos_indexed = [];
        foreach ($latest_week_memos as $memo) {
            $weekday_index = Carbon::parse($memo->memo_date)->dayOfWeekIso;
            $latest_week_memos_indexed[$weekday_index] = $memo;
        }

        for ($i = 1; $i <= 7; $i++) {
            if (!isset($latest_week_memos_indexed[$i])) {
                $template_processor->setValue("memo_date_$i", '');
                for ($j = 0; $j < 5; $j++) {
                    $template_processor->setValue("memo[$j]_$i", '……………………………………………………………………………………');
                }
                $template_processor->setValue("note_today_$i", '');
            } else {
                $memo = $latest_week_memos_indexed[$i];
                $thai_date = $this->formatThaiDate($memo->memo_date);
                $template_processor->setValue("number_of_week", $current_week_number);
                $template_processor->setValue("memo_date_$i", $thai_date);
                for ($j = 0; $j < 5; $j++) {
                    $template_processor->setValue("memo[$j]_$i", $this->getMemo($memo->memo, $j));
                }
                $template_processor->setValue("note_today_$i", $memo->note_today);
            }
        }
        $file_name = $user_info['student_id'] . '_week' . $current_week_number . '_memo.docx';
        $file_path = public_path($directory . DIRECTORY_SEPARATOR . $file_name);
        $template_processor->saveAs($file_path);
        return $file_path;
    }
    private function formatThaiDate($date)
    {
        $thai_months = [
            '01' => 'ม.ค.',
            '02' => 'ก.พ.',
            '03' => 'มี.ค.',
            '04' => 'เม.ย.',
            '05' => 'พ.ค.',
            '06' => 'มิ.ย.',
            '07' => 'ก.ค.',
            '08' => 'ส.ค.',
            '09' => 'ก.ย.',
            '10' => 'ต.ค.',
            '11' => 'พ.ย.',
            '12' => 'ธ.ค.'
        ];

        $year = (int) date('Y', strtotime($date)) + 543;
        $month = date('m', strtotime($date));
        $day = date('d', strtotime($date));

        return "$day {$thai_months[$month]} $year";
    }

    //function_setinfo
    protected function setInfoForm($chat_id)
    {
        $user_info = User::where('telegram_chat_id', $chat_id)->first();
        if ($user_info) {
            $text = "คุณได้ตั้งค่าข้อมูลส่วนตัวของคุณไปแล้ว!\n";
            $text .= "ถ้าคุณต้องการแก้ไขข้อมูลให้ใช้คำสั่ง /editinfo";
        } else {
            $text = "กรุณากรอกข้อมูลตามนี้:\n";
            $text .= "1. ชื่อ-นามสกุล\n";
            $text .= "2. รหัสนิสิต\n";
            $text .= "3. เบอร์โทรศัพท์\n";
            $text .= "4. สาขาวิชา\n";
            $text .= "5. สถานประกอบการ\n";
            $text .= "กรุณากรอกข้อมูลตามรูปแบบดังกล่าว\n";
            cache()->put("chat_id_{$chat_id}_start_set_info", 'waiting_for_command', now()->addMinutes(60));
        }
        $result = app('telegram_bot')->sendMessage($chat_id, $text);
        return response()->json($result, 200);
    }

    protected function showSetInfoForm($request, $chat_id)
    {
        $user_information_lines = explode("\n", $request->message['text']);
        if (count($user_information_lines) === 5) {
            $name = trim($user_information_lines[0]);
            $student_id = trim($user_information_lines[1]);
            $phone_number = trim(preg_replace('/\D/', '', $user_information_lines[2]));
            $branch = isset($user_information_lines[3]) ? trim($user_information_lines[3]) : '';
            $company = isset($user_information_lines[4]) ? trim($user_information_lines[4]) : '';

            $text = "ข้อมูลที่คุณกรอกมีดังนี้:\n";
            $text .= "ชื่อ-นามสกุล: $name\n";
            $text .= "รหัสนิสิต: $student_id\n";
            $text .= "เบอร์โทรศัพท์: $phone_number\n";
            $text .= "สาขาวิชา: $branch\n";
            $text .= "สถานประกอบการ: $company\n";
            $text .= "ถูกต้องมั้ยคะ? (กรุณาตอบ yes หรือ /cancel)";

            $result = app('telegram_bot')->sendMessage($chat_id, $text);

            cache()->put("chat_id_{$chat_id}_start_set_info", 'confirm', now()->addMinutes(60));
            cache()->put("chat_id_{$chat_id}_user_info", compact('name', 'student_id', 'phone_number', 'branch', 'company'));
            return response()->json($result, 200);
        } else {
            $text = "กรุณากรอกข้อมูลให้ครบถ้วนตามรูปแบบที่กำหนด:\n";
            $text .= "ชื่อ-นามสกุล\n";
            $text .= "รหัสนิสิต\n";
            $text .= "เบอร์โทรศัพท์\n";
            $text .= "สาขาวิชา\n";
            $text .= "สถานประกอบการ";
            $result = app('telegram_bot')->sendMessage($chat_id, $text);
            return response()->json($result, 200);
        }
    }

    // protected function handleConfirmation($chat_id, $request)
    // {
    //     $confirmation_text = 'yes';
    //     $text = $request->message['text'];
    //     if ($text === $confirmation_text) {
    //         $user_info = cache()->get("chat_id_{$chat_id}_user_info");
    //         if ($user_info) {
    //             $this->saveUserInfo($user_info, $chat_id);
    //             app('telegram_bot')->sendMessage($chat_id, "บันทึกข้อมูลเรียบร้อยแล้ว", );
    //             cache()->forget("chat_id_{$chat_id}_user_info");
    //             cache()->forget("chat_id_{$chat_id}_start_set_info");
    //         } else {
    //             app('telegram_bot')->sendMessage($chat_id, "ไม่พบข้อมูล user");
    //         }
    //     } elseif ($text === '/cancel') {
    //         app('telegram_bot')->sendMessage($chat_id, "ยกเลิกการ /setinfo");
    //         cache()->forget("chat_id_{$chat_id}_user_info");
    //         cache()->forget("chat_id_{$chat_id}_start_set_info");
    //     } else {
    //         app('telegram_bot')->sendMessage($chat_id, "/setinfo กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ");
    //     }
    // }

    protected function handleConfirmation( //everything
        $request,
        $chat_id,
        $cacheKeys,
        $cancel_message,
        $update_callback = null
    ) {
        $confirmation_text = 'yes';
        $text = $request->message['text'];

        if ($text === $confirmation_text) {
            if ($update_callback && is_callable($update_callback)) {
                $update_callback();
            } else {
                app('telegram_bot')->sendMessage($chat_id, "ไม่พบข้อมูล user");
            }
        } elseif ($text === '/cancel') {
            app('telegram_bot')->sendMessage($chat_id, $cancel_message);
            foreach ($cacheKeys as $cacheKey) {
                cache()->forget($cacheKey);
            }
        } else {
            app('telegram_bot')->sendMessage($chat_id, "กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ");
        }
    }
    public function saveUserInfo(array $user_info, $chat_id)
    {
        User::create([
            'name' => $user_info['name'],
            'student_id' => $user_info['student_id'],
            'phone_number' => $user_info['phone_number'],
            'branch' => $user_info['branch'],
            'company' => $user_info['company'],
            'telegram_chat_id' => $chat_id
        ]);
    }
    public function getUserInfo($telegram_chat_id)
    {
        $user_info = User::where('telegram_chat_id', $telegram_chat_id)->first();
        return $user_info;
    }
    //function_setreminder
    public function setReminder(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $user_info = $this->getUserInfo($chat_id);
        if ($user_info['memo_time'] && $user_info['summary_time']) {
            $text = "คุณตั้งค่าเวลาแจ้งเตือนจดบันทึกงานประจำวัน\n";
            $text .= "และตั้งค่าเวลาสรุปงานประจำวัน เรียบร้อยแล้ว!\n";
            $text .= "หากต้องการแก้ไข สามารถ /editreminder";
            $result = app('telegram_bot')->sendMessage($chat_id, $text);

            return response()->json($result, 200);
        } else if ($user_info['memo_time'] && !$user_info['summary_time']) {
            $text = "คุณตั้งค่าเวลาแจ้งเตือนจดบันทึกงานประจำวัน เรียบร้อยแล้ว!\n";
            $text .= "กรุณา /forsummary เพื่อตั้งค่าแจ้งเตือนสรุปงานประจำวัน\n";
            $result = app('telegram_bot')->sendMessage($chat_id, $text);
            cache()->put("chat_id_{$chat_id}_set_reminder", 'waiting_for_command', now()->addMinutes(60));

            return response()->json($result, 200);
        } else if (!$user_info['memo_time'] && $user_info['summary_time']) {
            $text = "คุณตั้งค่าเวลาแจ้งเตือนจดบันทึกงานประจำวัน เรียบร้อยแล้ว!\n";
            $text .= "กรุณา /formemo เพื่อตั้งค่าแจ้งเตือนสรุปงานประจำวัน\n";
            $result = app('telegram_bot')->sendMessage($chat_id, $text);
            cache()->put("chat_id_{$chat_id}_set_reminder", 'waiting_for_command', now()->addMinutes(60));

            return response()->json($result, 200);
        } else {
            $text = "กรุณาเลือกประเภทการแจ้งเตือนเพื่อตั้งค่าเวลา:\n";
            $text .= "1. /formemo - แจ้งเตือนจดบันทึกงานประจำวัน\n";
            $text .= "2. /forsummary - แจ้งเตือนสรุปงานประจำวัน\n";
            $result = app('telegram_bot')->sendMessage($chat_id, $text);

            cache()->put("chat_id_{$chat_id}_start_set_reminder", 'waiting_for_command', now()->addMinutes(60));

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

        cache()->put("chat_id_{$chat_id}_start_edit_reminder", 'waiting_for_command', now()->addMinutes(60));

        return response()->json($result, 200);
    }

    private function handleReminderConfirmation(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $text = strtolower(trim($request->input('message.text')));
        $confirmation_text = 'yes';
        $text_reply = '';
        if ($text === $confirmation_text) {
            $set_reminder_time = cache()->get("chat_id_{$chat_id}_set_reminder");
            if ($set_reminder_time) {
                switch ($set_reminder_time['type']) {
                    case '/formemo':
                        User::where('telegram_chat_id', $chat_id)->update([
                            'memo_time' => $set_reminder_time['time'],
                        ]);
                        $text_reply = "ตั้งค่าเวลาแจ้งเตือนเริ่มจดบันทึกงานประจำวันเรียบร้อยแล้ว!";
                        break;
                    case '/forsummary':
                        User::where('telegram_chat_id', $chat_id)->update([
                            'summary_time' => $set_reminder_time['time'],
                        ]);
                        $text_reply = "ตั้งค่าเวลาแจ้งเตือนสรุปงานประจำวันเรียบร้อยแล้ว!";
                        break;
                    default:
                        break;
                }
                app('telegram_bot')->sendMessage($chat_id, $text_reply);
                cache()->forget("chat_id_{$chat_id}_set_reminder");
                cache()->forget("chat_id_{$chat_id}_start_set_reminder");
            } else {
                app('telegram_bot')->sendMessage($chat_id, "ไม่พบข้อมูล user");
            }
        } elseif ($text === '/cancel') {
            app('telegram_bot')->sendMessage($chat_id, "ยกเลิกการ /setreminder");
            cache()->forget("chat_id_{$chat_id}_set_reminder");
            cache()->forget("chat_id_{$chat_id}_start_set_reminder");
        } else {
            app('telegram_bot')->sendMessage($chat_id, "/setreminder กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ");
        }

    }

    private function handleEditReminderConfirmation(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $text = strtolower(trim($request->input('message.text')));
        $confirmation_text = 'yes';
        $text_reply = '';
        if ($text === $confirmation_text) {
            $set_reminder_time = cache()->get("chat_id_{$chat_id}_edit_reminder");
            if ($set_reminder_time) {
                switch ($set_reminder_time['type']) {
                    case '/formemo':
                        User::where('telegram_chat_id', $chat_id)->update([
                            'memo_time' => $set_reminder_time['time'],
                        ]);
                        $text_reply = "แก้ไขเวลาแจ้งเตือนเริ่มจดบันทึกงานประจำวันเรียบร้อยแล้ว!";
                        break;
                    case '/forsummary':
                        User::where('telegram_chat_id', $chat_id)->update([
                            'summary_time' => $set_reminder_time['time'],
                        ]);
                        $text_reply = "แก้ไขเวลาแจ้งเตือนสรุปงานประจำวันเรียบร้อยแล้ว!";
                        break;
                    default:
                        break;
                }
                app('telegram_bot')->sendMessage($chat_id, $text_reply);
                cache()->forget("chat_id_{$chat_id}_start_edit_reminder");
                cache()->forget("chat_id_{$chat_id}_edit_reminder");
            } else {
                app('telegram_bot')->sendMessage($chat_id, "ไม่พบข้อมูล user");
            }
        } elseif ($text === '/cancel') {
            app('telegram_bot')->sendMessage($chat_id, "ยกเลิกการ /editreminder");
            cache()->forget("chat_id_{$chat_id}_start_edit_reminder");
            cache()->forget("chat_id_{$chat_id}_edit_reminder");
        } else {
            app('telegram_bot')->sendMessage($chat_id, "/editreminder กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ");
        }

    }
    //memo

    private function getMemo($memo, $index)
    {
        if ($memo) {
            $memoArray = explode(',', $memo);
            return isset($memoArray[$index]) ? trim($memoArray[$index]) : '……………………………………………………………………………………';
        } else {
            return '……………………………………………………………………………………';
        }
    }
    public function editMemoDairy(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $user_memo = $this->getUserMemo($chat_id);
        if (!$user_memo || !$user_memo['memo'] || (!$user_memo['memo'] && !$user_memo['note_today'])) {
            $text = "คุณยังไม่ได้จดบันทึกงานประจำวัน!\n";
            $text .= "กรุณา /memo เพื่อเริ่มจดบันทึกประจำวัน";
            $result = app('telegram_bot')->sendMessage($chat_id, $text);
            return response()->json($result, 200);
        } elseif ($user_memo['memo']) {
            $current_memo = explode(', ', $user_memo['memo']);
            $formatted_memo = [];
            foreach ($current_memo as $key => $memo) {
                $formatted_memo[] = ($key + 1) . ". " . $memo;
            }
            $text = "กรุณาเลือกบันทึกที่ต้องการแก้ไข:\n" . implode("\n", $formatted_memo);
            $text .= "\nกรุณาตอบเพียงตัวเลขเดียวเท่านั้น ";
            cache()->put("chat_id_{$chat_id}_start_edit_memo_dairy", 'waiting_for_command', now()->addMinutes(60));
            $result = app('telegram_bot')->sendMessage($chat_id, $text);
            return response()->json($result, 200);
        }
    }
    public function addMemoDairy(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $user_memo = $this->getUserMemo($chat_id);
        if (!$user_memo || !$user_memo['memo'] || (!$user_memo['memo'] && !$user_memo['note_today'])) {
            $text = "คุณยังไม่ได้จดบันทึกงานประจำวัน!\n";
            $text .= "กรุณา /memo เพื่อเริ่มจดบันทึกประจำวัน";
            $result = app('telegram_bot')->sendMessage($chat_id, $text);
            return response()->json($result, 200);
        } elseif ($user_memo['memo']) {
            $text = "สามารถพิมพ์ข้อความใดๆเพื่อเพิ่มบันทึกงานประจำวันได้เลยค่ะ\n";
            $text .= "ยกตัวอย่าง 'Create function CRUD'\n";
            $text .= "เมื่อจดบันทึกครบแล้ว ให้พิมพ์ /end เพื่อจบการบันทึก\n";
            $result = app('telegram_bot')->sendMessage($chat_id, $text);
            cache()->put("chat_id_{$chat_id}_start_add_memo_dairy", 'waiting_for_command', now()->addMinutes(60));
            return response()->json($result, 200);
        }
    }
    public function memoDairy(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $user_memo = $this->getUserMemo($chat_id);
        if (!$user_memo || !$user_memo['memo']) {
            $text = "สามารถพิมพ์ข้อความใดๆเพื่อจดบันทึกงานประจำวันได้เลยค่ะ\n";
            $text .= "ยกตัวอย่าง 'Create function CRUD'\n";
            $text .= "เมื่อจดบันทึกครบแล้ว ให้พิมพ์ /end เพื่อจบการบันทึก\n";
            $result = app('telegram_bot')->sendMessage($chat_id, $text);
            cache()->put("chat_id_{$chat_id}_start_memo_dairy", 'waiting_for_command', now()->addMinutes(60));
            cache()->put("chat_id_{$chat_id}_memo_daily", [], now()->addMinutes(60));
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
        $current_date = Carbon::now()->toDateString();
        $user_memo = Memo::where('user_id', $telegram_chat_id)->where('memo_date', $current_date)->first();
        return $user_memo;
    }
}