<?php
$data = $_POST['data'];
//error_log(">>>print>>> $data");
$bin = base64_decode($data, true);
file_put_contents('/tmp/temp.pdf', $bin);

$str = "\e@Hello World!\n" . chr(29) . "VA" . chr(3);
echo base64_encode($str);