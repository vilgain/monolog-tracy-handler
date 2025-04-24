<?php declare(strict_types = 1);

namespace Mangoweb\MonologTracyHandler\RemoteStorageDrivers;

use Mangoweb\Clock\Clock;
use Mangoweb\MonologTracyHandler\RemoteStorageDriver;
use Mangoweb\MonologTracyHandler\RemoteStorageRequestSender;


class AwsS3RemoteStorageDriver implements RemoteStorageDriver
{
	private const UNSIGNED_PAYLOAD_HASH = 'UNSIGNED-PAYLOAD';

	public function __construct(
		private string $region,
		private string $bucket,
		private string $prefix,
		private string $accessKeyId,
		private string $secretKey,
		private RemoteStorageRequestSender $requestSender,
		private ?string $securityToken = null,
	) {
	}


	public function getUrl(string $localName): string
	{
		$host = $this->getUrlHost();
		$path = $this->getUrlPath($localName);
		return "https://{$host}{$path}";
	}


	public function upload(string $localPath): bool
	{
		$localName = basename($localPath);
		$url = $this->getUrl($localName);
		$path = $this->getUrlPath($localName);

		$method = 'PUT';

		$headers = [
			'Host' => $this->getUrlHost(),
			'User-Agent' => 'MangoLogger',
			'X-Amz-ACL' => 'public-read',
			'X-Amz-Content-Sha256' => self::UNSIGNED_PAYLOAD_HASH,
			'X-Amz-Date' => Clock::now()->format('Ymd\THis\Z'),
		];
		if ($this->securityToken) {
			$headers['X-Amz-Security-Token'] = $this->securityToken;
		}

		$headers['Authorization'] = $this->getAuthorizationHeader($method, $path, $headers, self::UNSIGNED_PAYLOAD_HASH);
		$headers['Content-Type'] = 'text/html; charset=utf-8'; // cannot be included in the Authorization signature

		try {
			return $this->requestSender->sendRequest($method, $url, $headers, $localPath);

		} catch (\Throwable $e) {
			return false;
		}
	}


	private function getUrlHost(): string
	{
		return "s3.{$this->region}.amazonaws.com";
	}


	private function getUrlPath(string $localName): string
	{
		$hash = hash_hmac('md5', $localName, $this->secretKey);
		$prefix = ltrim($this->prefix, '/');
		return "/{$this->bucket}/{$prefix}{$hash}.html";
	}


	/**
	 * @param array<string, string> $headers
	 */
	private function getAuthorizationHeader(string $method, string $path, array $headers, string $payloadHash): string
	{
		$credentialScope = sprintf('%s/%s/s3/aws4_request', Clock::now()->format('Ymd'), $this->region);
		$canonicalRequest = $this->getCanonicalRequest($method, $path, $headers, $payloadHash);
		$stringToSign = $this->getStringToSign($credentialScope, $canonicalRequest);
		$signingKey = $this->getSigningKey();
		$signature = hash_hmac('sha256', $stringToSign, $signingKey);

		return sprintf(
			'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			$this->accessKeyId,
			$credentialScope,
			$this->getSignedHeaderNames($headers),
			$signature
		);
	}


	/**
	 * @param array<string, string> $headers
	 */
	private function getCanonicalRequest(string $method, string $path, array $headers, string $payloadHash): string
	{
		$query = '';
		$signedHeaderNames = $this->getSignedHeaderNames($headers);
		$signedHeaderLines = $this->getSignedHeaderLines($headers);
		return "{$method}\n{$path}\n{$query}\n{$signedHeaderLines}\n\n{$signedHeaderNames}\n{$payloadHash}";
	}


	/**
	 * @param array<string, string> $headers
	 */
	private function getSignedHeaderNames(array $headers): string
	{
		$headerNames = array_keys($headers);
		$headerNames = array_map('strtolower', $headerNames);
		sort($headerNames);

		return implode(';', $headerNames);
	}


	/**
	 * @param array<string, string> $headers
	 */
	private function getSignedHeaderLines(array $headers): string
	{
		$signedHeaderLines = [];
		$headers = array_change_key_case($headers, CASE_LOWER);
		foreach (explode(';', $this->getSignedHeaderNames($headers)) as $headerName) {
			$signedHeaderLines[] = sprintf('%s:%s', $headerName, $headers[$headerName]);
		}

		return implode("\n", $signedHeaderLines);
	}


	private function getStringToSign(string $credentialScope, string $canonicalRequest): string
	{
		$longDate = Clock::now()->format('Ymd\THis\Z');
		$hash = hash('sha256', $canonicalRequest);
		return "AWS4-HMAC-SHA256\n{$longDate}\n{$credentialScope}\n{$hash}";
	}


	private function getSigningKey(): string
	{
		$dateKey = hash_hmac('sha256', Clock::now()->format('Ymd'), "AWS4{$this->secretKey}", true);
		$regionKey = hash_hmac('sha256', $this->region, $dateKey, true);
		$serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
		return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
	}
}
