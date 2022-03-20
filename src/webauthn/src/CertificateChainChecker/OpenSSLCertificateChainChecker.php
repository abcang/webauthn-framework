<?php

declare(strict_types=1);

namespace Webauthn\CertificateChainChecker;

use Assert\Assertion;
use function count;
use const FILE_APPEND;
use InvalidArgumentException;
use function is_int;
use const PHP_EOL;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Symfony\Component\Process\Process;
use Webauthn\CertificateToolbox;

final class OpenSSLCertificateChainChecker implements CertificateChainChecker
{
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory
    ) {
    }

    /**
     * @param string[] $authenticatorCertificates
     * @param string[] $trustedCertificates
     */
    public function check(array $authenticatorCertificates, array $trustedCertificates): void
    {
        if (count($trustedCertificates) === 0) {
            $this->checkCertificatesValidity($authenticatorCertificates, true);

            return;
        }
        //$this->checkCertificatesValidity($authenticatorCertificates, false);

        $crls = [];
        $processArguments = ['-no-CAfile', '-no-CApath', '-no-CAstore'];

        //$caDirname = $this->createTemporaryDirectory();

        //Trusted Certificates (from the MDS)
        foreach ($trustedCertificates as $certificate) {
            $trustedCertificateFilename = $this->saveToTemporaryFile($certificate, 'webauthn-trusted-', '.pem');
            $processArguments[] = '-trusted';
            $processArguments[] = $trustedCertificateFilename;
            $filenames[] = $trustedCertificateFilename;
            $crl = $this->getCrls($certificate);
            if ($crl !== '') {
                $crls[] = CertificateToolbox::convertDERToPEM($crl, 'X509 CRL');
            }
        }
        foreach ($authenticatorCertificates as $certificate) {
            $crl = $this->getCrls($certificate);
            if ($crl !== '') {
                $crls[] = CertificateToolbox::convertDERToPEM($crl, 'X509 CRL');
            }
        }

        /*if (count($crls) !== 0) {
            array_unshift($processArguments, '-crl_check');
            array_unshift($processArguments, '-crl_check_all');
            array_unshift($processArguments, '-crl_download');
            array_unshift($processArguments, '-extended_crl');
            $crlsData = implode(PHP_EOL, $crls);
            $crlsFilename = $this->saveToTemporaryFile($crlsData, 'webauthn-crls-', '.pem');
            $processArguments[] = '-CRLfile';
            $processArguments[] = $crlsFilename;
            $filenames[] = $crlsFilename;
        }*/

        $leafCertificate = array_shift($authenticatorCertificates);

        //Untrusted Certificates (from the authenticator)
        if (count($authenticatorCertificates) !== 0) {
            $untrustedFilename = $this->saveToTemporaryFile(
                implode(PHP_EOL, $authenticatorCertificates),
                'webauthn-untrusted-',
                '.pem'
            );
            $processArguments[] = '-untrusted';
            $processArguments[] = $untrustedFilename;
            $filenames[] = $untrustedFilename;
        }

        //Leaf Certificate
        $leafFilename = $this->saveToTemporaryFile($leafCertificate, 'webauthn-leaf-', '.pem');
        $filenames[] = $leafFilename;
        $processArguments[] = $leafFilename;

        //Process Options
        array_unshift($processArguments, 'openssl', 'verify');

        $process = new Process($processArguments);
        $process->run();
        while ($process->isRunning()) {
            //Just wait
        }

        //dump($process->getCommandLine(), $filenames);
        foreach ($filenames as $filename) {
            unlink($filename);
        }

        if (! $process->isSuccessful()) {
            throw new InvalidArgumentException(
                'Invalid certificate or certificate chain. The error is: ' . $process->getErrorOutput()
            );
        }
    }

    /**
     * @param string[] $certificates
     */
    private function checkCertificatesValidity(array $certificates, bool $allowRootCertificate): void
    {
        foreach ($certificates as $certificate) {
            $parsed = openssl_x509_parse($certificate);
            Assertion::isArray($parsed, 'Unable to read the certificate. Submitted data was: ' . $certificate);
            if ($allowRootCertificate === false) {
                $this->checkRootCertificate($parsed);
            }

            Assertion::keyExists($parsed, 'validTo_time_t', 'The certificate has no validity period');
            Assertion::keyExists($parsed, 'validFrom_time_t', 'The certificate has no validity period');
            Assertion::lessOrEqualThan(time(), $parsed['validTo_time_t'], 'The certificate expired');
            Assertion::greaterOrEqualThan(time(), $parsed['validFrom_time_t'], 'The certificate is not usable yet');
        }
    }

    /**
     * @param array<string, mixed> $parsed
     */
    private function checkRootCertificate(array $parsed): void
    {
        Assertion::keyExists($parsed, 'subject', 'The certificate has no subject');
        Assertion::keyExists($parsed, 'issuer', 'The certificate has no issuer');
        $subject = $parsed['subject'];
        $issuer = $parsed['issuer'];
        ksort($subject);
        ksort($issuer);
        Assertion::notEq($subject, $issuer, 'Root certificates are not allowed');
    }

    private function saveToTemporaryFile(string $certificate, string $prefix, string $suffix): string
    {
        $filename = tempnam(sys_get_temp_dir(), $prefix);
        Assertion::string($filename, 'Unable to create a temporary folder');
        rename($filename, $filename . $suffix);
        file_put_contents($filename . $suffix, $certificate, FILE_APPEND);

        return $filename . $suffix;
    }

    private function getCrls(string $certificate): string
    {
        $parsed = openssl_x509_parse($certificate);
        if ($parsed === false || ! isset($parsed['extensions']['crlDistributionPoints'])) {
            return '';
        }
        $endpoint = $parsed['extensions']['crlDistributionPoints'];
        $pos = mb_strpos($endpoint, 'URI:');
        if (! is_int($pos)) {
            return '';
        }
        $endpoint = trim(mb_substr($endpoint, $pos + 4));

        $request = $this->requestFactory->createRequest('GET', $endpoint);
        $response = $this->client->sendRequest($request);

        if ($response->getStatusCode() !== 200) {
            return '';
        }

        return $response->getBody()
            ->getContents()
        ;
    }
}
