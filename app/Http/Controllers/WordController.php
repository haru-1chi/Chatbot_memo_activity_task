<?php

// namespace App\Http\Controllers;
// use PhpOffice\PhpWord\TemplateProcessor;
// use App\Models\User;
// use Illuminate\Http\Request;

// class WordController extends Controller
// {
//     public function index()
//     {
//         $users = User::all();
//         return view(view: 'user.index');
//     }

//     public function wordExport($id)
//     {
//         $users = User::findOrFail($id);
//         $templateProcessor = new TemplateProcessor(documentTemplate: 'word-templete/user.doc');
//         $templateProcessor->setValue(search:'id', $users->id);
//         $templateProcessor->setValue(search:'name', $users->name);
//         $fileName = $users->name;
//         $templateProcessor->saveAs(fileName: $fileName.'.docx');
//     return response()->download(file: $fileName.'.docx')->deleteFileAfterSend(shouldDelete:true);
//     }
// }
namespace App\Http\Controllers;

use PhpOffice\PhpWord\TemplateProcessor;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\Memo;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;
use Carbon\Carbon;

class WordController extends Controller
{


    public function testRender()
    {
        $weekNumber = 1;
        $memos = Memo::all();
        return view('word-summary', compact('weekNumber', 'memos'));
    }

    public function downloadDocx()
    {
        $documentPath = $this->generateDocument();
        return response()->download($documentPath, 'memo_week.docx');
    }


    public function generateDocument()
    {
        $templateProcessor = new TemplateProcessor('word-template/user.docx');
    
        $memos = Memo::where('user_id', '6810241495')->get();
        $currentWeekStartDate = null;
        $currentWeekNumber = 0;
        foreach ($memos as $memo) {
        
            $memoDate = Carbon::parse($memo->memo_date);

            if (!$currentWeekStartDate || !$memoDate->isSameWeek($currentWeekStartDate, Carbon::MONDAY)) {
                $currentWeekStartDate = $memoDate;
                $currentWeekNumber++;
            }
            $weekdayIndex = $memoDate->dayOfWeekIso;
    
            // Populate the template with memo data for the corresponding weekday
            $templateProcessor->setValue("number_of_week", $currentWeekNumber);
            $templateProcessor->setValue("memo_date_$weekdayIndex", $memo->memo_date);
            $templateProcessor->setValue("memo[0]_$weekdayIndex", $this->getMemo($memo->memo, 0));
            $templateProcessor->setValue("memo[1]_$weekdayIndex", $this->getMemo($memo->memo, 1));
            $templateProcessor->setValue("memo[2]_$weekdayIndex", $this->getMemo($memo->memo, 2));
            $templateProcessor->setValue("memo[3]_$weekdayIndex", $this->getMemo($memo->memo, 3));
            $templateProcessor->setValue("memo[4]_$weekdayIndex", $this->getMemo($memo->memo, 4));
            $templateProcessor->setValue("note_today_$weekdayIndex", $memo->note_today);
        }
    
        $fileName = "generated_document.docx";
        $templateProcessor->saveAs($fileName);
    
        return $fileName;
    }

    private function getMemo($memo, $index)
    {
        $memoArray = explode(',', $memo);
        return isset($memoArray[$index]) ? trim($memoArray[$index]) : '……………………………………………………………………………………';
    }

}