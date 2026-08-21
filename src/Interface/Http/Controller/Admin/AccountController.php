<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Admin;

use App\Application\Transfer\Message\LoginAccountMessage;
use App\Domain\Transfer\Enum\Platform;
use App\Infrastructure\Browser\BrowserProfileSnapshotStorage;
use App\Infrastructure\Persistence\Entity\Account;
use App\Infrastructure\Persistence\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class AccountController extends AbstractController
{
    public function __construct(
        private AccountRepository $accountRepository,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private BrowserProfileSnapshotStorage $profileStorage,
    ) {
    }

    #[Route('/admin/accounts', name: 'admin_accounts', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/accounts/index.html.twig', [
            'accounts' => $this->accountRepository->findAllOrdered(),
        ]);
    }

    #[Route('/admin/accounts/new', name: 'admin_accounts_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('account_new', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token');
            }

            $email = trim((string) $request->request->get('email'));
            if ($email === '') {
                $this->addFlash('error', 'Email обязателен');

                return $this->redirectToRoute('admin_accounts_new');
            }

            if ($this->accountRepository->findByEmail($email) instanceof Account) {
                $this->addFlash('error', 'Аккаунт уже существует');

                return $this->redirectToRoute('admin_accounts');
            }

            try {
                $platform = Platform::from((string) $request->request->get('platform', 'ps'));
            } catch (\ValueError) {
                $this->addFlash('error', 'Неверная платформа');

                return $this->redirectToRoute('admin_accounts_new');
            }

            $account = new Account($email, $platform);
            $account->setCredentials(
                $this->nullableString($request->request->get('password')),
                $this->nullableString($request->request->get('totp')),
                $this->nullableString($request->request->get('proxy')),
            );
            $this->entityManager->persist($account);
            $this->entityManager->flush();

            $this->addFlash('success', 'Аккаунт создан: '.$account->getEmail());

            return $this->redirectToRoute('admin_accounts');
        }

        return $this->render('admin/accounts/form.html.twig', [
            'account' => null,
            'platforms' => Platform::cases(),
        ]);
    }

    #[Route('/admin/accounts/{id}/edit', name: 'admin_accounts_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $account = $this->requireAccount($id);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('account_edit_'.$account->getId()->toRfc4122(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token');
            }

            try {
                $account->setPlatform(Platform::from((string) $request->request->get('platform', 'ps')));
            } catch (\ValueError) {
                $this->addFlash('error', 'Неверная платформа');

                return $this->redirectToRoute('admin_accounts_edit', ['id' => $id]);
            }

            $account->setCredentials(
                $this->nullableString($request->request->get('password')),
                $this->nullableString($request->request->get('totp')),
                $this->nullableString($request->request->get('proxy')),
            );
            $account->setActive($request->request->getBoolean('is_active'));
            $this->entityManager->flush();

            $this->addFlash('success', 'Сохранено: '.$account->getEmail());

            return $this->redirectToRoute('admin_accounts');
        }

        return $this->render('admin/accounts/form.html.twig', [
            'account' => $account,
            'platforms' => Platform::cases(),
        ]);
    }

    #[Route('/admin/accounts/{id}/login', name: 'admin_accounts_login', methods: ['POST'])]
    public function login(string $id, Request $request): Response
    {
        $account = $this->requireAccount($id);
        if (!$this->isCsrfTokenValid('account_login_'.$account->getId()->toRfc4122(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $this->messageBus->dispatch(new LoginAccountMessage($account->getId()->toRfc4122()));
        $this->addFlash('success', 'Логин поставлен в очередь: '.$account->getEmail().'. Смотрите worker / scraping.log');

        return $this->redirectToRoute('admin_accounts');
    }

    #[Route('/admin/accounts/{id}/clear-profile', name: 'admin_accounts_clear_profile', methods: ['POST'])]
    public function clearProfile(string $id, Request $request): Response
    {
        $account = $this->requireAccount($id);
        if (!$this->isCsrfTokenValid('account_clear_profile_'.$account->getId()->toRfc4122(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $deleted = $this->profileStorage->deleteProfileForEmail($account->getEmail());
        $account->markLoginUnknown('Профиль браузера очищен вручную');
        $this->entityManager->flush();

        $this->addFlash(
            'success',
            $deleted
                ? 'Профиль Chrome очищен: '.$account->getEmail().'. Нажмите Login заново.'
                : 'Профиль уже был пуст: '.$account->getEmail(),
        );

        return $this->redirectToRoute('admin_accounts');
    }

    private function requireAccount(string $id): Account
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        $account = $this->accountRepository->find(Uuid::fromString($id));
        if (!$account instanceof Account) {
            throw $this->createNotFoundException();
        }

        return $account;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
