<?php declare(strict_types = 1);

namespace Mangoweb\MonologTracyHandler\RemoteStorageDrivers;

use Aws\Credentials\CredentialsInterface;
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
		/**
		 * @var callable
		 */
		private $credentialProvider,
		private RemoteStorageRequestSender $requestSender,
	) {
	}

	private function getCredentials(): CredentialsInterface
	{
		$fn = $this->credentialProvider;
		return $fn()->wait();
	}


	public function getUrl(string $localName): string
	{
		$host = $this->getUrlHost();
		$path = $this->getUrlPath($localName, $this->getCredentials());
		return "https://{$host}{$path}";
	}


	public function upload(string $localPath): bool
	{
		$credentials = $this->getCredentials();
		$localName = basename($localPath);
		$url = $this->getUrl($localName);
		$path = $this->getUrlPath($localName, $credentials);

		$method = 'PUT';

		$headers = [
			'Host' => $this->getUrlHost(),
			'User-Agent' => 'MangoLogger',
			'X-Amz-ACL' => 'public-read',
			'X-Amz-Content-Sha256' => self::UNSIGNED_PAYLOAD_HASH,
			'X-Amz-Date' => Clock::now()->format('Ymd\THis\Z'),
		];
		if ($securityToken = $credentials->getSecurityToken()) {
			$headers['X-Amz-Security-Token'] = $securityToken;
		}

		$headers['Authorization'] = $this->getAuthorizationHeader($method, $path, $headers, self::UNSIGNED_PAYLOAD_HASH, $credentials);
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


	private function getUrlPath(string $localName, CredentialsInterface $credentials): string
	{
		$hash = hash_hmac('md5', $localName, $credentials->getSecretKey());
		$prefix = ltrim($this->prefix, '/');
		return "/{$this->bucket}/{$prefix}{$hash}.html";
	}


	/**
	 * @param array<string, string> $headers
	 */
	private function getAuthorizationHeader(string $method, string $path, array $headers, string $payloadHash, CredentialsInterface $credentials): string
	{
		$credentialScope = sprintf('%s/%s/s3/aws4_request', Clock::now()->format('Ymd'), $this->region);
		$canonicalRequest = $this->getCanonicalRequest($method, $path, $headers, $payloadHash);
		$stringToSign = $this->getStringToSign($credentialScope, $canonicalRequest);
		$signingKey = $this->getSigningKey($credentials);
		$signature = hash_hmac('sha256', $stringToSign, $signingKey);

		return sprintf(
			'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			$credentials->getAccessKeyId(),
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


	private function getSigningKey(CredentialsInterface $credentials): string
	{
		$dateKey = hash_hmac('sha256', Clock::now()->format('Ymd'), "AWS4{$credentials->getSecretKey()}", true);
		$regionKey = hash_hmac('sha256', $this->region, $dateKey, true);
		$serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
		return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
	}
}
