<?php

declare(strict_types=1);

namespace Supseven\ThemeDev\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Authentication\Mfa\Provider\RecoveryCodes;
use TYPO3\CMS\Core\Authentication\Mfa\Provider\Totp;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Create or update a BE-user with MFA
 *
 * First- ane last name must be provided. Other information is
 * infered according to supseven guidelines.
 *
 * @author Georg Großberger <g.grossberger@supseven.at>
 */
#[AsCommand('supseven:be_user')]
class BackendUserCommand extends Command
{
    public function __construct(
        protected readonly ConnectionPool $connectionPool,
        protected readonly PasswordHashFactory $passwordHashFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('first_name', InputArgument::REQUIRED, 'First Name');
        $this->addArgument('last_name', InputArgument::REQUIRED, 'Last Name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!Environment::getContext()->isDevelopment()) {
            $io->error('Only available in development mode');

            return self::INVALID;
        }

        $firstname = mb_trim($input->getArgument('first_name'));

        if (mb_strlen($firstname) < 2 || mb_strlen($firstname) > 100) {
            $io->error('First name must be between 2 and 100 characters');

            return self::INVALID;
        }

        $lastname = mb_trim($input->getArgument('last_name'));

        if (mb_strlen($lastname) < 2 || mb_strlen($lastname) > 100) {
            $io->error('Last name must be between 2 and 100 characters');

            return self::INVALID;
        }

        $translit = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()');

        $key = ucfirst(strtolower(substr($firstname, 0, 2))) . strtoupper(substr($lastname, 0, 1));
        $username = 'admin-sup7-' . strtolower($key);

        $hasher = $this->passwordHashFactory->getDefaultHashInstance('BE');
        $passwordChars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789,.-_:;öä#+üßÖÄ*Ü?!§$%&/()=}][{';
        $passwordMax = mb_strlen($passwordChars) - 1;
        $password = '';

        while (mb_strlen($password) < 48) {
            $password .= mb_substr($passwordChars, random_int(0, $passwordMax), 1);
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('be_users');
        $qb->getRestrictions()->removeAll();
        $qb->select('uid', 'uc');
        $qb->from('be_users');
        $qb->where($qb->expr()->like('username', $qb->createNamedParameter($username)));

        $existing = $qb->executeQuery()->fetchAllAssociative()[0] ?? [];

        $uid = $existing['uid'] ?? null;
        $uc = $existing['uc'] ?? null;

        if ($uc) {
            $uc = @unserialize($uc);
        }

        if (!is_array($uc)) {
            $uc = [];
        }

        $uc['mfa'] ??= [];
        $uc['mfa']['defaultProvider'] = 'totp';

        $recoveryCodes = (new RecoveryCodes('BE'))->generateRecoveryCodes();

        $totpToken = Totp::generateEncodedSecret([$uid ?? '', $username]);
        $mfa = json_encode([
            'totp' => [
                'secret'  => $totpToken,
                'active'  => true,
                'name'    => '',
                'created' => time(),
                'updated' => time(),
            ],
            'recovery-codes' => [
                'codes'   => array_values($recoveryCodes),
                'active'  => true,
                'created' => time(),
                'updated' => time(),
            ],
        ]);

        $record = [
            'username' => $username,
            'email'    => substr($translit->transliterate($firstname), 0, 1) . '.' . $translit->transliterate($lastname) . '@supseven.at',
            'realName' => $firstname . ' ' . $lastname,
            'password' => $hasher->getHashedPassword($password),
            'tstamp'   => time(),
            'admin'    => 1,
            'disable'  => 0,
            'deleted'  => 0,
            'pid'      => 0,
            'uc'       => serialize($uc),
            'mfa'      => $mfa,

            // Not needed fields, they are set to be in sync with
            // the values DataHandler would set
            'description'      => null,
            'avatar'           => 0,
            'options'          => 3,
            'workspace_perms'  => 1,
            'userMods'         => null,
            'file_mountpoints' => null,
            'file_permissions' => 'readFolder,writeFolder,addFolder,renameFolder,moveFolder,deleteFolder,readFile,writeFile,addFile,renameFile,replaceFile,moveFile,copyFile,deleteFile',
            'TSConfig'         => null,
            'category_perms'   => null,
            'workspace_id'     => 0,
        ];

        $cnx = $this->connectionPool->getConnectionForTable('be_users');

        if (!$uid) {
            $io->caution('Creating new user record');
            $record['crdate'] = time();
            $record['lang'] = 'default';

            $cnx->insert('be_users', $record);
            $uid = $cnx->lastInsertId();
        } else {
            $io->caution('Updating user record ' . $uid);
            $cnx->update('be_users', $record, ['uid' => $uid]);
        }

        $table = [
            ['UID', $uid],
            ['Username', $username],
            ['Password', $password],
            ['TOTP', $totpToken],
            ['Codes', implode("\n", array_keys($recoveryCodes))],
        ];

        $io->table(['Field', 'Value'], $table);

        return self::SUCCESS;
    }
}
