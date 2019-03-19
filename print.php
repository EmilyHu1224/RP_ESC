<?php
require_once "src/Pdf2text.php";
require __DIR__ . '/vendor/autoload.php';
use Mike42\Escpos\Printer;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;

define("AMOUNT_CHARACTER", "0123456789.");
define("DEFAULT_RECEIPT_WIDTH", 550);
define("QR_CODE_CAPTION", "Please scan the following QR code with WeChatPay or AliPay to complete payment:");
define("QR_PAY_URL", "https://dev.riverpayments.com/t_shipped/rest/gen_payment_qr");

/*
 * Directories & filenames
 */
define("TEMP_PATH", "/tmp");
define("INPUT_FILENAME", TEMP_PATH . "/input.pdf");
define("OUTPUT_FILENAME", TEMP_PATH . "/output.txt");
define("IMAGE_FORMAT", "png");
define("IMAGE_FILENAME", TEMP_PATH . "/output." . IMAGE_FORMAT);

function is_amount_char($c){
    /*
     * Check if the character is an amount character
     * i.e. is either a numieric digit or a decimal point
     */
    return strpos(AMOUNT_CHARACTER, $c) !== false;
}

/*
 * Decode the input PDF
 */
$data_raw = $_POST["data"];
$bin = base64_decode($data_raw, true);
file_put_contents(INPUT_FILENAME, $bin);

/*
 * Retrieve the token and
 * fetch the terminal-associated configurations
 * TODO:
 *  - token: retrieved from request
 *  - keyword: DB
 *  - receipt_width: DB
 */
$token = "demomike11";
$keyword = "Total USD:\n";
$receipt_width = DEFAULT_RECEIPT_WIDTH;

/*
 * Decode the input PDF
 */
try {
    $reader = new \Asika\Pdf2text;
    $data = $reader->decode(INPUT_FILENAME);
} catch (Exception $e) {
    error_log(">>>>print.php: PDF decode failed: " . $e->getMessage() . "\n");
    exit();
}

/*
 * Extract the amount and
 * CURL the t_shipped endpoint to retrieve the payment URL
 */
$url = null;
$pos = strpos($data, $keyword);
$amount = "";
if ($pos) {
    $pos += strlen($keyword);
    for ($i = $pos; $i < strlen($data); $i++) {
        if (is_amount_char($data[$i])) {
            $amount .= $data[$i];
        } else {
            break;
        }
    }
} else {
    error_log(">>>>print.php: Pattern match failed: data:\n$data\n");
}

if ($amount) {
    $parameters = "token=".urlencode($token).
        "&total=".round($amount*100);
    try {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, QR_PAY_URL);
        curl_setopt($curl, CURLOPT_FAILONERROR, true);
        curl_setopt($curl,CURLOPT_POST, 7);
        curl_setopt($curl,CURLOPT_POSTFIELDS, $parameters);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $result = curl_exec($curl);
        if (curl_error($curl)) {
            error_log(">>>>print: " . curl_error($curl) . "\n");
        }
        curl_close($curl);
        $r = json_decode($result, true);
        if ($r['result'] == 'success') {
            $url = $r['qr_code'];
        }
    } catch (Exception $e) {
        error_log(">>>>print.php: CURL failed: " . $e->getMessage() . "\n");
    }
} else {
    error_log(">>>>print.php: Amount read failed: pos = $pos\n");
}

/*
 * Convert the PDF to an image
 */
$im = null;
try {
    $im = new Imagick();
    $im -> setResolution(550,550);
    $im -> readimage(INPUT_FILENAME);
    $im -> resizeImage($receipt_width, 0, Imagick::FILTER_UNDEFINED, 1);
    $im -> trimImage(0);
    $im -> setImageFormat(IMAGE_FORMAT);
    $im -> writeImage(IMAGE_FILENAME);
} catch (Exception $e) {
    error_log(">>>>print: Imagick failed: " . $e->getMessage() . "\n");
    exit();
} finally {
    if ($im) {
        $im -> clear();
        $im -> destroy();
    }
}

/*
 * Generate the out ESC file
 */
$printer = null;
try {
    $connector = new FilePrintConnector(OUTPUT_FILENAME);
    $printer = new Printer($connector);
    $img  = EscposImage::load(IMAGE_FILENAME);
    $printer -> graphics($img);

    if ($url) {
        $printer -> feed();
        $printer -> setJustification(Printer::JUSTIFY_CENTER);
        $printer -> text(QR_CODE_CAPTION);
        $printer -> feed();
        $printer -> qrCode($url, Printer::QR_ECLEVEL_L, 10);
        $printer -> setJustification();
    }

    $printer -> feed();
    $printer -> cut();
} catch (Exception $e) {
    error_log(">>>>print.php: ESC generation failed: " . $e->getMessage() . "\n");
} finally {
    if ($printer) {
        $printer -> close();
    }
}

/*
 * Respond with the generated ESC data
 */
try {
    $output = file_get_contents(OUTPUT_FILENAME);
    echo base64_encode($output);
} catch (Exception $e) {
    error_log(">>>>print.php: Output file read failed: " . $e->getMessage() . "\n");
}