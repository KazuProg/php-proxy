<?php
/*	このPHPを格納しているURL上のパス
 *	例)http://example.com/index.php => ''
 *	例)http://example.com/proxy/index.php => '/proxy'
 */
$this_path = '/proxy'; //	このPHPを格納しているURL上のパス

/*	リクエスト先のURL(+Path)
 *	例)http://hogehoge.jp/
 *	例)http://hogehoge.jp/~user/
 */
$request_path = 'http://localhost:8080/';

//	エラー時に例外をスローするように登録
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
	if (!(error_reporting() & $errno)) {
		return;
	}
	throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
});

$path = urldecode($_SERVER['REQUEST_URI']);
if ($this_path != '' && strpos($path, $this_path) == 0) {
	$path = substr($path, strlen($this_path));
}

//	ヘッダ情報をそのままリクエスト
$header_text = "";
foreach (apache_request_headers() as $name => $value) {
	if ($name == 'Host') continue;
	if ($name == 'Referer') continue;	//	これがないと403を返すサーバあり(StarServerFree)
	$header_text .= "{$name}: {$value}\r\n";
}

//	POSTメソッド等のbody部を読み込み
$content = '';
$handle_in = fopen("php://input", "rb");
while (!feof($handle_in)) {
	$content .= fread($handle_in, 8192);
}

//	リクエストコンテキスト作成
$context = stream_context_create(array(
	'http' => array(
		'method' => $_SERVER['REQUEST_METHOD'],
		'content' => $content,
		'header' => $header_text,
		'ignore_errors' => true,	//	400番台等もエラーを出さない
		'max_redirects' => 0,		//	リダイレクトを自動で処理しない
	),
));

//	ストリームの取得
try {
	$stream = fopen($request_path . $path, 'rb', false, $context);
} catch (Exception $e) {
	//	$e->getMessage()
	//	  fopen(http://localhost:8080/): failed to open stream: Connection refused
	//	初めの"fopen(...):"の部分を取り除く
	print("Proxy: " . substr($e->getMessage(), strpos($e->getMessage(), ': ') + 2));
	exit();
}

//	URLが存在しない等でストリームが開けなかった場合
if ($stream == false) {
	print("Proxy: Failed to open stream.");
	exit;
}

//	レスポンスヘッダをクライアントへ返す
//	ついでにbody部のサイズも取得
$header = stream_get_meta_data($stream)['wrapper_data'];
$content_length = -1;
foreach ($header as $h) {
	list($key, $value) = explode(' ', $h);
	//	これを消すと2回目以降の読み込みが遅くなる！？
	if ($key == 'Last-Modified:') continue;
	if ($key == 'Content-Length:') {
		$content_length = (int)$value;
	}
	header($h);
}

//	レスポンスbody部をクライアントへ返す
if ($content_length != 0) {
	$read_size = 0;
	while (!feof($stream)) {
		$content = fread($stream, 8192);
		echo $content;
		ob_flush();

		$read_size += strlen($content);
		if (
			strlen($content) == 0 ||
			$content_length != -1 && $read_size == $content_length
		) break;
	}
}
ob_end_flush();

//	ストリームを閉じる
@fclose($stream);
