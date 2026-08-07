<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Admin;

use App\Application\Transfer\Message\PairTestMessage;
use App\Application\Transfer\Service\TransferOrchestrator;
use App\Infrastructure\Persistence\Entity\Account;
use App\Infrastructure\Persistence\Entity\Transfer;
use App\Infrastructure\Persistence\Repository\AccountRepository;
use App\Infrastructure\Persistence\Repository\TransferRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class TransferController extends AbstractController
{
    public function __construct(
        private AccountRepository $accountRepository,
        private TransferRepository $transferRepository,
        private TransferOrchestrator $orchestrator,
        private MessageBusInterface $messageBus,
    ) {
    }

    #[Route('/admin/transfers', name: 'admin_transfers', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/transfers/index.html.twig', [
            'transfers' => $this->transferRepository->findRecent(50),
        ]);
    }

    #[Route('/admin/transfers/new', name: 'admin_transfers_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $accounts = $this->accountRepository->findActive();
        $plan = null;
        $amount = 50_000;
        $selectedReceiver = null;
        $selectedSender = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('transfer_new', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token');
            }

            $amount = max(1, (int) $request->request->get('amount'));
            $receiverId = (string) $request->request->get('receiver');
            $senderId = trim((string) $request->request->get('sender'));
            $selectedReceiver = $receiverId;
            $selectedSender = $senderId !== '' ? $senderId : null;
            $action = (string) $request->request->get('action', 'plan');

            try {
                $receiver = $this->requireAccount($receiverId);
                $senderUuid = null;
                if ($selectedSender !== null) {
                    $senderUuid = $this->requireAccount($selectedSender)->getId();
                }

                $plan = $this->orchestrator->planTransfer($receiver->getId(), $amount);

                if ($action === 'run') {
                    if (($plan['players'] ?? []) === []) {
                        $this->addFlash('error', 'План пуст — нет игроков с ценами');
                    } elseif (($plan['senders'] ?? []) === [] && $senderUuid === null) {
                        $this->addFlash('error', 'Нет доступных отправителей');
                    } else {
                        $transfer = $this->orchestrator->createAndQueueTransfer(
                            $receiver->getId(),
                            $amount,
                            $senderUuid,
                        );
                        $this->addFlash('success', 'Перевод в очереди: '.$transfer->getId()->toRfc4122());

                        return $this->redirectToRoute('admin_transfers_show', ['id' => $transfer->getId()->toRfc4122()]);
                    }
                }
            } catch (\InvalidArgumentException|\RuntimeException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->render('admin/transfers/new.html.twig', [
            'accounts' => $accounts,
            'plan' => $plan,
            'amount' => $amount,
            'selectedReceiver' => $selectedReceiver,
            'selectedSender' => $selectedSender,
        ]);
    }

    #[Route('/admin/transfers/pair-test', name: 'admin_pair_test', methods: ['GET', 'POST'])]
    public function pairTest(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('pair_test', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token');
            }

            try {
                $sender = $this->requireAccount((string) $request->request->get('sender'));
                $receiver = $this->requireAccount((string) $request->request->get('receiver'));
                $playerIdRaw = trim((string) $request->request->get('player_id'));
                $playerName = trim((string) $request->request->get('player_name'));

                $this->messageBus->dispatch(new PairTestMessage(
                    senderId: $sender->getId()->toRfc4122(),
                    receiverId: $receiver->getId()->toRfc4122(),
                    buyMax: max(150, (int) $request->request->get('buy_max')),
                    listPrice: max(150, (int) $request->request->get('list_price')),
                    buyAny: $request->request->getBoolean('buy_any'),
                    buyFirst: $request->request->getBoolean('buy_first'),
                    playerName: $playerName !== '' ? $playerName : null,
                    playerFutbinId: $playerIdRaw !== '' ? (int) $playerIdRaw : null,
                ));

                $this->addFlash(
                    'success',
                    sprintf('Pair-test в очереди: %s → %s', $sender->getEmail(), $receiver->getEmail()),
                );

                return $this->redirectToRoute('admin_pair_test');
            } catch (\InvalidArgumentException|\RuntimeException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->render('admin/transfers/pair_test.html.twig', [
            'accounts' => $this->accountRepository->findActive(),
        ]);
    }

    #[Route('/admin/transfers/{id}', name: 'admin_transfers_show', methods: ['GET'])]
    public function show(string $id): Response
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        $transfer = $this->transferRepository->find(Uuid::fromString($id));
        if (!$transfer instanceof Transfer) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/transfers/show.html.twig', [
            'transfer' => $transfer,
        ]);
    }

    private function requireAccount(string $id): Account
    {
        if (!Uuid::isValid($id)) {
            throw new \InvalidArgumentException('Некорректный UUID аккаунта');
        }

        $account = $this->accountRepository->find(Uuid::fromString($id));
        if (!$account instanceof Account) {
            throw new \InvalidArgumentException('Аккаунт не найден');
        }

        return $account;
    }
}
