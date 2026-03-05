<?php

if( !defined('IN_SCRIPT') ) die( "Hacking attempt" );

class JSONEncrypt {

	// AES-256-GCM is the industry standard for AEAD
	const CIPHER = 'aes-256-gcm';
	const TAG_LENGTH = 16; // Standard GCM tag size

	private static $keyCache = [];

	/**
	 * Convert password into 32-byte binary key.
	 * TODO: Once we move to 32 byte hex keys (64 chars long) this will change to `hex2bin($key)`
	 */
	private static function getKey( $password ) {

		// 1. Check Cache (Fastest)
		if( isset(self::$keyCache[$password]) ) {

			return self::$keyCache[$password];

		}

		// 2. Generate Key
		$binaryKey = hash('sha256', $password, true);

		/* Future version
		$binaryKey = hex2bin($key);

		if( $binaryKey === false || strlen($binaryKey) !== 32 ) {

			throw new InvalidArgumentException("Key must be a 64-character hex string");

		}
		*/

		// 3. Store in Cache
		self::$keyCache[$password] = $binaryKey;

		return $binaryKey;

	}

	public static function encode($payload, $key) {

		// Convert "password" style key to a 32-byte binary key
		$keyBin = self::getKey( $key );

		/* Re-enable once we move to 32 byte hex keys
		// 1. Validate Key (Must be 32 bytes / 256 bits)
		if( strlen($keyBin) !== 32 ) {

			throw new Exception("Key must be 32 bytes (raw binary) for AES-256");

		}
		*/

		// Use the helper to ensure JSON errors throw exceptions
		$plaintext = self::jsonEncode($payload);

		// 2. Generate IV (12 bytes is standard for GCM)
		$ivlen = openssl_cipher_iv_length(self::CIPHER);
		$iv = random_bytes($ivlen);

		// 3. Encrypt
		// $tag is passed by reference and populated by OpenSSL
		$tag = "";
		$ciphertext_raw = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$keyBin,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			"", // AAD (Optional)
			self::TAG_LENGTH
		);

		if( $ciphertext_raw === false ) {

			throw new Exception("Encryption failed");

		}

		// 4. Bundle (IV + Tag + Ciphertext)
		return self::urlsafeB64Encode($iv . $tag . $ciphertext_raw);
	}

	public static function decode($ciphertext, $key) {

		// Convert "password" style key to a 32-byte binary key
		$keyBin = self::getKey( $key );

		/* Re-enable once we move to 32 byte hex keys
		if( strlen($key) !== 32 ) {

			throw new Exception("Key must be 32 bytes (raw binary) for AES-256");

		}
		*/

		if( !$c = self::urlsafeB64Decode($ciphertext) ) {

			return false;

		}

		$ivlen = openssl_cipher_iv_length(self::CIPHER);
		$totalLen = strlen($c);

		// Basic Length Check
		if( $totalLen < $ivlen + self::TAG_LENGTH) {

			return false;

		}

		// 1. Extract Components
		$iv = substr($c, 0, $ivlen);
		$tag = substr($c, $ivlen, self::TAG_LENGTH);
		$ciphertext_raw = substr($c, $ivlen + self::TAG_LENGTH);

		// 2. Decrypt & Verify
		// GCM verifies the Tag against the IV and Ciphertext internally.
		$original_plaintext = openssl_decrypt(
			$ciphertext_raw,
			self::CIPHER,
			$keyBin,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		// If decryption fails (or tag mismatch), this returns false.
		if( $original_plaintext === false ) {

			return false;

		}

		// 3. Decode JSON
		// Returns the object or throws exception on JSON error
		return self::jsonDecode($original_plaintext);

	}

	/**
	 * @param string $input JSON string
	 *
	 * @return object Object representation of JSON string
	 */
	public static function jsonDecode($input)
	{
		$obj = json_decode($input);
		if (function_exists('json_last_error') && $errno = json_last_error()) {
			self::handleJsonError($errno);
		}
		else if ($obj === null && $input !== 'null') {
			throw new DomainException('Null result with non-null input');
		}
		return $obj;
	}

	/**
	 * @param object|array $input A PHP object or array
	 *
	 * @return string JSON representation of the PHP object or array
	 */
	public static function jsonEncode($input)
	{
		$json = json_encode($input);
		if (function_exists('json_last_error') && $errno = json_last_error()) {
			self::handleJsonError($errno);
		}
		else if ($json === 'null' && $input !== null) {
			throw new DomainException('Null result with non-null input');
		}
		return $json;
	}

	/**
	 * @param string $input A base64 encoded string
	 *
	 * @return string A decoded string
	 */
	public static function urlsafeB64Decode($input)
	{
		$remainder = strlen($input) % 4;
		if ($remainder) {
			$padlen = 4 - $remainder;
			$input .= str_repeat('=', $padlen);
		}
		return base64_decode(strtr($input, '-_', '+/'));
	}

	/**
	 * @param string $input Anything really
	 *
	 * @return string The base64 encode of what you passed in
	 */
	public static function urlsafeB64Encode($input)
	{
		return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
	}

	/**
	 * @param int $errno An error number from json_last_error()
	 *
	 * @return void
	 */
	private static function handleJsonError($errno)
	{
		$messages = array(
			JSON_ERROR_DEPTH => 'Maximum stack depth exceeded',
			JSON_ERROR_CTRL_CHAR => 'Unexpected control character found',
			JSON_ERROR_SYNTAX => 'Syntax error, malformed JSON'
		);
		throw new DomainException(isset($messages[$errno])
			? $messages[$errno]
			: 'Unknown JSON error: ' . $errno
		);
	}
	
}
