<?php

namespace App\Security\Voter;

use App\Entity\Conversation;
use App\Entity\Utilisateur;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Une conversation n'est lisible et utilisable que par ses deux participants.
 *
 * @extends Voter<string, Conversation>
 */
final class ConversationVoter extends Voter
{
    public const PARTICIPER = 'CONVERSATION_PARTICIPER';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::PARTICIPER === $attribute && $subject instanceof Conversation;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $utilisateur = $token->getUser();

        return $utilisateur instanceof Utilisateur && $subject->estParticipant($utilisateur);
    }
}
