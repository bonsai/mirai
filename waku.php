<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ★これより上にいかなる空白行や文字も入れないこと★
ob_start(); // 出力バッファリングを開始

// デバッグ情報を収集するための配列
$debug_info = [];

// --- デバッグ: アップロードされたファイル情報 ---
$debug_info['FILES'] = $_FILES;
$debug_info['POST'] = $_POST;

// アップロードされた画像の一時ファイル名
$uploaded_image_tmp_name = $_FILES['selfie_image']['tmp_name'] ?? null;
$uploaded_image_name = $_FILES['selfie_image']['name'] ?? 'uploaded_image.jpg';

$debug_info['uploaded_image_tmp_name'] = $uploaded_image_tmp_name;
$debug_info['uploaded_image_name'] = $uploaded_image_name;
$debug_info['tmp_file_exists'] = $uploaded_image_tmp_name ? file_exists($uploaded_image_tmp_name) : false;

// waku.gif のパス
$frame_path = 'waku.gif';
$debug_info['frame_path'] = $frame_path;
$debug_info['frame_file_exists'] = file_exists($frame_path);

// --- アップロードエラーのチェック ---
if (!isset($_FILES['selfie_image'])) {
    $debug_info['error'] = 'selfie_image not found in $_FILES';
    output_debug_and_exit($debug_info);
}

if ($_FILES['selfie_image']['error'] !== UPLOAD_ERR_OK) {
    $debug_info['upload_error'] = $_FILES['selfie_image']['error'];
    $debug_info['upload_error_message'] = get_upload_error_message($_FILES['selfie_image']['error']);
    output_debug_and_exit($debug_info);
}

// --- waku.gif の読み込み ---
if (!file_exists($frame_path)) {
    $debug_info['error'] = "waku.gif が見つかりません";
    $debug_info['current_directory'] = getcwd();
    $debug_info['directory_contents'] = scandir('.');
    output_debug_and_exit($debug_info);
}

$frame_image = imagecreatefromgif($frame_path);
if ($frame_image === false) {
    $debug_info['error'] = "waku.gif の読み込みに失敗";
    $debug_info['gd_info'] = gd_info();
    output_debug_and_exit($debug_info);
}

$debug_info['frame_loaded'] = true;
$debug_info['frame_width'] = imagesx($frame_image);
$debug_info['frame_height'] = imagesy($frame_image);

// --- アップロード画像の読み込み ---
$image_info = getimagesize($uploaded_image_tmp_name);
if ($image_info === false) {
    imagedestroy($frame_image);
    $debug_info['error'] = "アップロードされたファイルは画像ではありません";
    $debug_info['tmp_file_size'] = $uploaded_image_tmp_name ? filesize($uploaded_image_tmp_name) : 'N/A';
    output_debug_and_exit($debug_info);
}

$debug_info['image_info'] = $image_info;
$uploaded_image_type = $image_info[2];
$debug_info['uploaded_image_type'] = $uploaded_image_type;
$debug_info['uploaded_image_type_name'] = image_type_to_extension($uploaded_image_type);

$uploaded_image = null;
switch ($uploaded_image_type) {
    case IMAGETYPE_JPEG:
        $uploaded_image = imagecreatefromjpeg($uploaded_image_tmp_name);
        $debug_info['image_creation_method'] = 'JPEG';
        break;
    case IMAGETYPE_PNG:
        $uploaded_image = imagecreatefrompng($uploaded_image_tmp_name);
        $debug_info['image_creation_method'] = 'PNG';
        break;
    case IMAGETYPE_GIF:
        $uploaded_image = imagecreatefromgif($uploaded_image_tmp_name);
        $debug_info['image_creation_method'] = 'GIF';
        break;
    default:
        imagedestroy($frame_image);
        $debug_info['error'] = "サポートされていない画像形式";
        output_debug_and_exit($debug_info);
}

if ($uploaded_image === false) {
    imagedestroy($frame_image);
    $debug_info['error'] = "アップロードされた画像の読み込みに失敗";
    output_debug_and_exit($debug_info);
}

$debug_info['uploaded_image_loaded'] = true;

// --- ここから画像合成処理 ---
$original_uploaded_width = imagesx($uploaded_image);
$original_uploaded_height = imagesy($uploaded_image);
$frame_width = imagesx($frame_image);
$frame_height = imagesy($frame_image);

$debug_info['original_uploaded_dimensions'] = ['width' => $original_uploaded_width, 'height' => $original_uploaded_height];
$debug_info['frame_dimensions'] = ['width' => $frame_width, 'height' => $frame_height];

// フォームからのサイズ調整設定を取得
$resize_mode = $_POST['resize_mode'] ?? 'auto';
$image_scale = floatval($_POST['image_scale'] ?? 100);
$position = $_POST['position'] ?? 'center';
$preserve_aspect = isset($_POST['preserve_aspect']);
$high_quality = isset($_POST['high_quality']);

$debug_info['resize_settings'] = [
    'mode' => $resize_mode,
    'scale' => $image_scale,
    'position' => $position,
    'preserve_aspect' => $preserve_aspect,
    'high_quality' => $high_quality
];

// サイズ調整処理
$uploaded_width = $original_uploaded_width;
$uploaded_height = $original_uploaded_height;
$resized_image = null;

switch ($resize_mode) {
    case 'fit_frame':
        // 枠のサイズに合わせてリサイズ
        if ($preserve_aspect) {
            // 縦横比を保持してフィット
            $scale_x = $frame_width / $original_uploaded_width;
            $scale_y = $frame_height / $original_uploaded_height;
            $scale = min($scale_x, $scale_y);
            $uploaded_width = intval($original_uploaded_width * $scale);
            $uploaded_height = intval($original_uploaded_height * $scale);
        } else {
            // 枠のサイズに完全に合わせる
            $uploaded_width = $frame_width;
            $uploaded_height = $frame_height;
        }
        break;
        
    case 'custom':
        // カスタムスケール
        $scale = $image_scale / 100.0;
        $uploaded_width = intval($original_uploaded_width * $scale);
        $uploaded_height = intval($original_uploaded_height * $scale);
        break;
        
    case 'auto':
    default:
        // 自動調整（元のサイズを維持）
        break;
}

// リサイズが必要な場合の処理
if ($uploaded_width != $original_uploaded_width || $uploaded_height != $original_uploaded_height) {
    $resized_image = imagecreatetruecolor($uploaded_width, $uploaded_height);
    
    // 透過をサポート
    imagealphablending($resized_image, false);
    imagesavealpha($resized_image, true);
    $transparent_resized = imagecolorallocatealpha($resized_image, 0, 0, 0, 127);
    imagefill($resized_image, 0, 0, $transparent_resized);
    
    // 高品質リサイズ
    if ($high_quality) {
        imagecopyresampled($resized_image, $uploaded_image, 
                          0, 0, 0, 0, 
                          $uploaded_width, $uploaded_height, 
                          $original_uploaded_width, $original_uploaded_height);
    } else {
        imagecopyresized($resized_image, $uploaded_image, 
                        0, 0, 0, 0, 
                        $uploaded_width, $uploaded_height, 
                        $original_uploaded_width, $original_uploaded_height);
    }
    
    imagedestroy($uploaded_image);
    $uploaded_image = $resized_image;
    
    $debug_info['image_resized'] = true;
    $debug_info['final_uploaded_dimensions'] = ['width' => $uploaded_width, 'height' => $uploaded_height];
}

// 合成後の画像のサイズを決定
$output_width = max($uploaded_width, $frame_width);
$output_height = max($uploaded_height, $frame_height);

$debug_info['output_dimensions'] = ['width' => $output_width, 'height' => $output_height];

// 新しいキャンバスを作成
$composite_image = imagecreatetruecolor($output_width, $output_height);
if ($composite_image === false) {
    imagedestroy($uploaded_image);
    imagedestroy($frame_image);
    $debug_info['error'] = "合成用キャンバスの作成に失敗";
    output_debug_and_exit($debug_info);
}

$debug_info['composite_canvas_created'] = true;

// 透過情報を保持するために、背景を透過に設定
imagealphablending($composite_image, false);
imagesavealpha($composite_image, true);
$transparent = imagecolorallocatealpha($composite_image, 0, 0, 0, 127);
imagefill($composite_image, 0, 0, $transparent);

$debug_info['transparency_setup'] = true;

// 座標を整数に変換（小数点による描画問題を回避）
// 位置設定に基づいて配置を計算
switch ($position) {
    case 'top':
        $dest_x_uploaded = intval(($output_width - $uploaded_width) / 2);
        $dest_y_uploaded = intval($output_height * 0.1); // 上部10%の位置
        break;
    case 'bottom':
        $dest_x_uploaded = intval(($output_width - $uploaded_width) / 2);
        $dest_y_uploaded = intval($output_height * 0.9 - $uploaded_height); // 下部10%の位置
        break;
    case 'center':
    default:
        $dest_x_uploaded = intval(($output_width - $uploaded_width) / 2);
        $dest_y_uploaded = intval(($output_height - $uploaded_height) / 2);
        break;
}

// 画像が枠外に出ないように調整
$dest_x_uploaded = max(0, min($dest_x_uploaded, $output_width - $uploaded_width));
$dest_y_uploaded = max(0, min($dest_y_uploaded, $output_height - $uploaded_height));

$debug_info['uploaded_image_position'] = ['x' => $dest_x_uploaded, 'y' => $dest_y_uploaded];

// アルファブレンディングを有効にして画像をコピー
imagealphablending($composite_image, true);
$copy_result1 = imagecopy($composite_image, $uploaded_image, 
                         $dest_x_uploaded, $dest_y_uploaded, 
                         0, 0, $uploaded_width, $uploaded_height);

$debug_info['uploaded_image_copy_result'] = $copy_result1;

// フレーム画像の透過色を処理
$dest_x_frame = intval(($output_width - $frame_width) / 2);
$dest_y_frame = intval(($output_height - $frame_height) / 2);

$debug_info['frame_position'] = ['x' => $dest_x_frame, 'y' => $dest_y_frame];

// GIFの透過色をチェック
$transparent_color = imagecolortransparent($frame_image);
$debug_info['frame_transparent_color'] = $transparent_color;

if ($transparent_color >= 0) {
    // 透過色が設定されている場合、imagecopymege を使用
    $copy_result2 = imagecopymerge($composite_image, $frame_image, 
                                  $dest_x_frame, $dest_y_frame, 
                                  0, 0, $frame_width, $frame_height, 100);
    $debug_info['frame_copy_method'] = 'imagecopymerge (with transparency)';
} else {
    // 通常のコピー
    $copy_result2 = imagecopy($composite_image, $frame_image, 
                             $dest_x_frame, $dest_y_frame, 
                             0, 0, $frame_width, $frame_height);
    $debug_info['frame_copy_method'] = 'imagecopy (normal)';
}

$debug_info['frame_copy_result'] = $copy_result2;

// デバッグモードかどうかをチェック
$debug_mode = isset($_GET['debug']) || isset($_POST['debug']);

if ($debug_mode) {
    // デバッグ情報を出力
    output_debug_and_exit($debug_info);
} else {
    // 通常の画像出力
    $output_format = $_POST['output_format'] ?? 'png';
    
    switch (strtolower($output_format)) {
        case 'jpg':
        case 'jpeg':
            header('Content-Type: image/jpeg');
            header('Content-Disposition: inline; filename="composite_image.jpg"');
            imagejpeg($composite_image, null, 92); // 品質92%
            break;
        case 'gif':
            header('Content-Type: image/gif');
            header('Content-Disposition: inline; filename="composite_image.gif"');
            imagegif($composite_image);
            break;
        default:
            header('Content-Type: image/png');
            header('Content-Disposition: inline; filename="composite_image.png"');
            imagepng($composite_image, null, 6); // 圧縮レベル6（0-9）
            break;
    }
}

// リソースを解放
imagedestroy($uploaded_image);
imagedestroy($frame_image);
imagedestroy($composite_image);

ob_end_flush();

// --- デバッグ用関数 ---
function output_debug_and_exit($debug_info) {
    ob_clean(); // バッファをクリア
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html>\n<html>\n<head>\n<title>Debug Information</title>\n";
    echo "<style>body{font-family:monospace;margin:20px;} pre{background:#f5f5f5;padding:10px;border:1px solid #ccc;} .error{color:red;font-weight:bold;}</style>\n";
    echo "</head>\n<body>\n";
    echo "<h1>Debug Information</h1>\n";
    
    if (isset($debug_info['error'])) {
        echo "<div class='error'>エラー: " . htmlspecialchars($debug_info['error']) . "</div>\n";
    }
    
    echo "<pre>" . htmlspecialchars(print_r($debug_info, true)) . "</pre>\n";
    echo "</body>\n</html>";
    exit;
}

function get_upload_error_message($error_code) {
    switch ($error_code) {
        case UPLOAD_ERR_OK:
            return 'アップロード成功';
        case UPLOAD_ERR_INI_SIZE:
            return 'ファイルサイズがphp.iniのupload_max_filesizeを超えています';
        case UPLOAD_ERR_FORM_SIZE:
            return 'ファイルサイズがフォームのMAX_FILE_SIZEを超えています';
        case UPLOAD_ERR_PARTIAL:
            return 'ファイルが部分的にしかアップロードされませんでした';
        case UPLOAD_ERR_NO_FILE:
            return 'ファイルがアップロードされませんでした';
        case UPLOAD_ERR_NO_TMP_DIR:
            return '一時ディレクトリがありません';
        case UPLOAD_ERR_CANT_WRITE:
            return 'ディスクに書き込めませんでした';
        case UPLOAD_ERR_EXTENSION:
            return 'PHPの拡張機能によってアップロードが停止されました';
        default:
            return '不明なエラー';
    }
}
?>