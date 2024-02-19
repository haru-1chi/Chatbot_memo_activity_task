<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แบบบันทึกภาระงาน</title>
    <style>
        /* Add your CSS styles here */
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            border: 1px solid black;
            padding: 8px;
            text-align: center;
        }
    </style>
</head>
<body>
    <h1>แบบบันทึกภาระงาน</h1>
    <h2>สัปดาห์ที่การปฏิบัติสหกิจศึกษา</h2>
    <h3>นิสิตคณะวิทยาศาสตร์ มหาวิทยาลัยนเรศวร</h3>
    <table>
        <thead>
            <tr>
                <th>no.</th>
                <th>date</th>
                <th>memo daily task</th>
                <th>note_today</th>
            </tr>
        </thead>
        <tbody>
            @foreach($memos as $index => $memo)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $memo['memo_date'] }}</td>
                <td>{{ $memo['memo'] }}</td>
                <td>{{ $memo['note_today'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <form action="/downloadDocx" method="GET">
        <button type="submit">Download Memo Document</button>
    </form>
</body>
</html>