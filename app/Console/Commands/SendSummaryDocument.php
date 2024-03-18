<?php

namespace App\Console\Commands;

use PhpOffice\PhpWord\TemplateProcessor;
use App\Services\TelegramBot;
use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Memo;

class SendSummaryDocument extends Command
{
    protected $telegramBot;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send:summary-document';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';
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
        $users = User::whereNotNull('telegram_chat_id')->get();

        foreach ($users as $user) {
            $this->sendSummaryDocumentToUser($user);
        }
    }


    /**
     * Send message to user using Telegram Bot service.
     *
     * @param int $chat_id
     * @param string $message
     * @return void
     */

    private function sendSummaryDocumentToUser($user)
    {
        $chat_id = $user->telegram_chat_id;
        $user_info = $this->getUserInfo($chat_id);
        if (!$user_info) {
            $this->sendMessageToUser($chat_id, 'คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!');
            return;
        }
        $user_memo = $this->getUserMemo($chat_id);
        if (!$user_memo) {
            $this->sendMessageToUser($chat_id, 'คุณยังไม่ได้จดบันทึกประจำวันใดๆเลย');
            return;
        }
        $word_path = $this->generateWord($chat_id);
        $this->sendDocumentToUser($chat_id, $word_path);
        $this->sendMessageToUser($chat_id, 'อย่าลืมดาวน์โหลดไฟล์แล้วส่งให้พนักงานที่ปรึกษาลงนามในทุกสัปดาห์ด้วยนะ');
    }

    public function getUserMemo($telegram_chat_id)
    {
        $current_date = now()->toDateString();
        $user_memo = Memo::where('user_id', $telegram_chat_id)->where('memo_date', $current_date)->first();
        return $user_memo;
    }

    public function getUserInfo($telegram_chat_id)
    {
        $user_info = User::where('telegram_chat_id', $telegram_chat_id)->first();
        return $user_info;
    }

    public function generateWord($chat_id)
    {
        $user_info = $this->getUserInfo($chat_id);
        $directory = 'word-send';
        if (!file_exists(public_path($directory))) {
            mkdir(public_path($directory), 0777, true);
        }
        $templatePath = public_path('word-template/user.docx');
        $template_processor = new TemplateProcessor($templatePath);
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

    private function getMemo($memo, $index)
    {
        if ($memo) {
            $memoArray = explode(',', $memo);
            return isset($memoArray[$index]) ? trim($memoArray[$index]) : '……………………………………………………………………………………';
        } else {
            return '……………………………………………………………………………………';
        }
    }
    
        /**
     * Send message to user using Telegram Bot service.
     *
     * @param int $chat_id
     * @param string $message
     * @param string $word_path
     * @return void
     */
    private function sendMessageToUser($chat_id, $message)
    {
        $this->telegramBot->sendMessage($chat_id, $message);
    }

    private function sendDocumentToUser($chat_id, $word_path)
    {
        $this->telegramBot->sendDocument($chat_id, $word_path);
    }
}
