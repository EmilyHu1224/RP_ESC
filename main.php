<?php
require_once "src/Pdf2text.php";
require __DIR__ . '/vendor/autoload.php';
use Mike42\Escpos\Printer;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;

define("AMOUNT_CHARACTER", "0123456789.");
define("QR_PAY_URL", "https://dev.riverpayments.com/t_shipped/rest/gen_payment_qr");
$filename = "1.pdf";
$image_format = "png";
$img_name = "out.".$image_format;
$new_width = 550;

function is_amount_char($c){
    return strpos(AMOUNT_CHARACTER, $c) !== false;
}

$token = "demomike11";
$url =  "";

$reader = new \Asika\Pdf2text;
$data = $reader->decode($filename);

$keyword = "Total USD:\n";
$pos = strpos($data, $keyword);
if ($pos) {
    $pos += strlen($keyword);
    $amount = "";
    for ($i = $pos; $i < strlen($data); $i++) {
        if (is_amount_char($data[$i])) {
            $amount .= $data[$i];
        } else {
            break;
        }
    }

    $parameters = "token=".urlencode($token).
        "&total=".round($amount*100);
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, QR_PAY_URL);
    curl_setopt($curl, CURLOPT_FAILONERROR, true);
    curl_setopt($curl,CURLOPT_POST, 7);
    curl_setopt($curl,CURLOPT_POSTFIELDS, $parameters);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    $result = curl_exec($curl);
    if (curl_error($curl)) {
        $error_msg = curl_error($curl);
    } else {
        $error_msg = "0";
    }
    curl_close($curl);
    $r = json_decode($result, true);
    if ($r['result'] == 'success') {
        $url = $r['qr_code'];
    }
}

try {
    $im = new Imagick();
    $im->setResolution(550,550);
    $im->readimage($filename);
    $im->resizeImage($new_width, 0, Imagick::FILTER_UNDEFINED, 1);
    $im->cropImage($new_width, 925, 0, 0);
    $im->setImageFormat($image_format);
    $im->writeImage($img_name);
    $im->clear();
    $im->destroy();
} catch (Exception $e) {
    echo $e->getMessage()."\n";
}

$connector = new FilePrintConnector("php://stdout");
$printer = new Printer($connector);

try {
    $img  = EscposImage::load($img_name);
    $printer -> graphics($img);

    if ($url) {
        $printer -> setJustification(Printer::JUSTIFY_CENTER);
        $printer -> text("Please scan the following QR code with WeChatPay or AliPay to complete payment:");
        $printer -> feed();
        $printer -> qrCode($url, Printer::QR_ECLEVEL_L, 10);
        $printer -> setJustification();
    }

    $printer -> feed();
    $printer -> cut();
} catch (Exception $e) {
    echo $e -> getMessage() . "\n";
} finally {
    $printer -> close();
}