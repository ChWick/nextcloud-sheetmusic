<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MobileSheetsManager\Service;

/**
 * The document security of a PDF that is protected with the Standard security
 * handler and an empty user password - the "restrictions" many publishers put
 * on their PDFs to stop copying, while everyone who has the file may open it.
 *
 * The encryption key is derived from the empty user password and the values of
 * the file's Encrypt dictionary, so the strings of the document - the metadata
 * fields among them - can be encrypted and decrypted without the owner
 * password. Anything beyond that scheme (a user password, AES-256, another
 * security handler) is refused: guessing a key is out of the question, and
 * writing plaintext strings into such a file would only corrupt it.
 */
class MobileSheetsPdfSecurity {
	/** The password padding string of the PDF specification, 32 bytes. */
	private const PADDING = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08"
		. "\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

	/** "sAlT", the fixed MD5 salt for AES encrypted objects. */
	private const AES_SALT = "\x73\x41\x6C\x54";

	private function __construct(
		/** The file encryption key. */
		private readonly string $key,
		/** Whether the strings of the file are AES encrypted ("AESV2"). */
		private readonly bool $aes,
	) {
	}

	/**
	 * The security of the file whose trailer region is given, or null when the
	 * file is not encrypted at all.
	 *
	 * @throws \RuntimeException when the file is encrypted in a way that is not
	 *                           supported, e.g. with a user password
	 */
	public static function forTrailer(string $content, string $region): ?self {
		if (preg_match('#/Encrypt\s+(\d+)\s+(\d+)\s+R#', $region, $reference) !== 1) {
			return null;
		}

		// The Encrypt dictionary is an ordinary object; like the Info dictionary
		// it may have been redefined by an incremental update, so the last
		// definition is the one in force. The endobj anchor keeps the capture at
		// the outer dictionary - the crypt filters of a version 4 file nest
		// dictionaries of their own.
		$pattern = '#(?<![0-9])' . $reference[1] . '\s+' . $reference[2] . '\s+obj\s*<<(.*?)>>\s*endobj#s';
		if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE) === false
			|| $matches[1] === []
		) {
			throw new \RuntimeException('Encrypted PDF: the Encrypt dictionary is missing');
		}
		$dict = (string)end($matches[1])[0];

		if (preg_match('#/Filter\s*/Standard\b#', $dict) !== 1) {
			throw new \RuntimeException('Encrypted PDF: only the Standard security handler is supported');
		}
		if (preg_match('#/R\s+(\d+)#', $dict, $revision) !== 1) {
			throw new \RuntimeException('Encrypted PDF: the security revision is missing');
		}
		$revisionNumber = (int)$revision[1];
		if ($revisionNumber < 2 || $revisionNumber > 4) {
			throw new \RuntimeException('Encrypted PDF: only the security revisions 2 to 4 are supported');
		}
		if (preg_match('#/V\s+(\d+)#', $dict, $version) === 1
			&& ((int)$version[1] < 1 || (int)$version[1] > 2)
		) {
			throw new \RuntimeException('Encrypted PDF: only the encryption versions 1 and 2 are supported');
		}

		if (preg_match('#/O\s*(<[0-9a-fA-F\s]*>|\((?:[^()\\\\]|\\\\.)*\))#', $dict, $owner) !== 1) {
			throw new \RuntimeException('Encrypted PDF: the /O entry is missing');
		}
		if (preg_match('#/U\s*(<[0-9a-fA-F\s]*>|\((?:[^()\\\\]|\\\\.)*\))#', $dict, $user) !== 1) {
			throw new \RuntimeException('Encrypted PDF: the /U entry is missing');
		}
		if (preg_match('#/P\s+(-?\d+)#', $dict, $permissions) !== 1) {
			throw new \RuntimeException('Encrypted PDF: the /P entry is missing');
		}

		// The file identifier takes part in the key; the first element of the
		// /ID array is the one the specification names.
		$id = '';
		if (preg_match('#/ID\s*\[\s*(<[0-9a-fA-F\s]*>|\((?:[^()\\\\]|\\\\.)*\))#', $region, $idMatch) === 1) {
			$id = self::pdfStringBytes($idMatch[1]);
		}

		$keyLength = $revisionNumber === 2
			? 5
			: (preg_match('#/Length\s+(\d+)#', $dict, $length) === 1 ? max(5, intdiv((int)$length[1], 8)) : 5);

		// "sAlT" also goes into the key derivation of a version 4 file whose
		// metadata streams are not encrypted - the salt marks the difference.
		$encryptMetadata = preg_match('#/EncryptMetadata\s+false#', $dict) !== 1;

		$key = self::deriveKey(
			self::pdfStringBytes($owner[1]),
			(int)$permissions[1],
			$id,
			$revisionNumber,
			$keyLength,
			$encryptMetadata,
		);

		if (!self::matchesUserPassword($key, self::pdfStringBytes($user[1]), $id, $revisionNumber)) {
			throw new \RuntimeException('Encrypted PDF: the user password is not empty');
		}

		$aes = false;
		if ($revisionNumber === 4 && preg_match('#/StrF\s*/(\w+)#', $dict, $stringFilter) === 1
			&& $stringFilter[1] !== 'Identity'
		) {
			$cfmPattern = '#/' . preg_quote($stringFilter[1], '#') . '\s*<<(.*?)>>#s';
			if (preg_match($cfmPattern, $dict, $filter) === 1
				&& preg_match('#/CFM\s*/(\w+)#', $filter[1], $method) === 1
			) {
				if ($method[1] !== 'V2' && $method[1] !== 'AESV2') {
					throw new \RuntimeException('Encrypted PDF: the crypt filter ' . $method[1] . ' is not supported');
				}
				$aes = $method[1] === 'AESV2';
			}
		}

		return new self($key, $aes);
	}

	/**
	 * The file encryption key of the Standard security handler, derived from the
	 * empty user password (Algorithm 2 of the specification).
	 */
	private static function deriveKey(
		string $ownerKey,
		int $permissions,
		string $id,
		int $revisionNumber,
		int $keyLength,
		bool $encryptMetadata,
	): string {
		$data = self::PADDING . $ownerKey . pack('V', $permissions) . $id;
		if ($revisionNumber >= 4 && !$encryptMetadata) {
			$data .= self::AES_SALT;
		}

		$key = substr(md5($data, true), 0, $keyLength);
		if ($revisionNumber >= 3) {
			for ($i = 0; $i < 50; $i++) {
				$key = substr(md5($key, true), 0, $keyLength);
			}
		}

		return $key;
	}

	/**
	 * Whether $key is the key the file was encrypted with, proven by the /U
	 * entry the empty user password computes to (Algorithm 5).
	 */
	private static function matchesUserPassword(string $key, string $userKey, string $id, int $revisionNumber): bool {
		if ($revisionNumber === 2) {
			$computed = self::rc4($key, self::PADDING);
		} else {
			$computed = self::rc4($key, md5(self::PADDING . $id, true));
			for ($i = 1; $i <= 19; $i++) {
				$computed = self::rc4(self::xorByte($key, $i), $computed);
			}
		}

		// Only the first 16 bytes of /U carry the check; the rest is padding.
		return hash_equals(substr($computed, 0, 16), substr($userKey, 0, 16));
	}

	/** The key one string of the given object is encrypted with (Algorithm 3.1). */
	private function objectKey(int $objectNumber, int $generation): string {
		$hash = md5(
			$this->key
			. substr(pack('V', $objectNumber), 0, 3)
			. pack('v', $generation)
			. ($this->aes ? self::AES_SALT : ''),
			true,
		);

		return substr($hash, 0, min(strlen($this->key) + 5, 16));
	}

	/**
	 * The bytes of a string of the given object, encrypted the way the file
	 * encrypts its strings. AES prepends the initialization vector it used.
	 */
	public function encryptBytes(string $plain, int $objectNumber, int $generation): string {
		$key = $this->objectKey($objectNumber, $generation);
		if (!$this->aes) {
			return self::rc4($key, $plain);
		}

		$iv = random_bytes(16);
		$cipher = openssl_encrypt($plain, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);
		if ($cipher === false) {
			throw new \RuntimeException('Could not encrypt the PDF string');
		}

		return $iv . $cipher;
	}

	/** The plaintext bytes of an encrypted string of the given object. */
	public function decryptBytes(string $cipher, int $objectNumber, int $generation): string {
		$key = $this->objectKey($objectNumber, $generation);
		if (!$this->aes) {
			return self::rc4($key, $cipher);
		}

		if (strlen($cipher) < 17) {
			throw new \RuntimeException('Could not decrypt the PDF string');
		}
		$plain = openssl_decrypt(substr($cipher, 16), 'aes-128-cbc', $key, OPENSSL_RAW_DATA, substr($cipher, 0, 16));
		if ($plain === false) {
			throw new \RuntimeException('Could not decrypt the PDF string');
		}

		return $plain;
	}

	/** RC4, the cipher of the security revisions 2 to 4 with the "V2" method. */
	private static function rc4(string $key, string $data): string {
		$schedule = range(0, 255);
		$keyBytes = array_values(unpack('C*', $key));
		$j = 0;
		foreach ($schedule as $i => $unused) {
			$j = ($j + $schedule[$i] + $keyBytes[$i % count($keyBytes)]) & 0xFF;
			[$schedule[$i], $schedule[$j]] = [$schedule[$j], $schedule[$i]];
		}

		$i = 0;
		$j = 0;
		$out = '';
		foreach (unpack('C*', $data) as $byte) {
			$i = ($i + 1) & 0xFF;
			$j = ($j + $schedule[$i]) & 0xFF;
			[$schedule[$i], $schedule[$j]] = [$schedule[$j], $schedule[$i]];
			$out .= chr($byte ^ $schedule[($schedule[$i] + $schedule[$j]) & 0xFF]);
		}

		return $out;
	}

	/** The key bytes xored with $value, the round keys of /U in revisions 3 and 4. */
	private static function xorByte(string $key, int $value): string {
		return $key ^ str_repeat(chr($value), strlen($key));
	}

	/** The bytes of a PDF string, whether it is written as a hex string or a literal one. */
	private static function pdfStringBytes(string $raw): string {
		if (str_starts_with($raw, '<')) {
			$hex = preg_replace('/\s+/', '', substr($raw, 1, -1)) ?? '';
			$bytes = hex2bin($hex);

			return $bytes === false ? '' : $bytes;
		}

		return preg_replace_callback(
			'/\\\\\\\\([nrtbf()\\\\\\\\]|[0-7]{1,3})/',
			static function (array $match): string {
				return match ($match[1]) {
					'n' => "\n",
					'r' => "\r",
					't' => "\t",
					'b' => "\x08",
					'f' => "\x0C",
					'(' => '(',
					')' => ')',
					'\\\\' => '\\\\',
					default => chr(octdec($match[1])),
				};
			},
			substr($raw, 1, -1),
		) ?? substr($raw, 1, -1);
	}
}
