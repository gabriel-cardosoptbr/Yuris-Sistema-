<?php
namespace App\WhatsAppAgente\AiIntake;

/**
 * IntakeStateMachine — estados da sessao de pre-atendimento (secao 10 do prompt mestre).
 *
 * As transicoes CRITICAS sao decididas pelo BACKEND (engine), nao pelo modelo. O modelo
 * nunca define o estado livremente; ele so fornece sinais (intent, enough_for_handoff,
 * urgency...) e o engine mapeia para um estado VALIDO via decide().
 */
final class IntakeStateMachine
{
    public const STATES = [
        'new', 'greeting', 'identifying_intent', 'collecting_initial_report',
        'identifying_area', 'collecting_minimum_data', 'checking_urgency',
        'ready_for_handoff', 'awaiting_human', 'human_takeover', 'completed',
        'paused', 'expired', 'disabled', 'error',
    ];

    /** Estados terminais (bot nao atua mais sem acao explicita). */
    public const TERMINAL = ['awaiting_human', 'human_takeover', 'completed', 'disabled', 'expired'];

    public static function isValid(string $state): bool
    {
        return in_array($state, self::STATES, true);
    }

    public static function isTerminal(string $state): bool
    {
        return in_array($state, self::TERMINAL, true);
    }

    /**
     * Decide o proximo estado a partir do estado atual + sinais do modelo + decisao do backend.
     * $decision: ['handoff'=>bool, 'immediate'=>bool, 'out_of_scope'=>bool, 'office_info'=>bool,
     *             'will_ask'=>bool].
     */
    public static function decide(string $current, array $structured, array $decision): string
    {
        if (!empty($decision['out_of_scope']))  return 'completed';
        if (!empty($decision['handoff']))       return 'awaiting_human';

        $intent = $structured['intent'] ?? 'unknown';
        if (!empty($decision['office_info']) || $intent === 'office_information') {
            return 'identifying_intent';
        }
        if (($structured['urgency_level'] ?? 'normal') !== 'normal') return 'checking_urgency';

        if (empty($structured['extracted_data']['main_report'])) return 'collecting_initial_report';
        if (empty($structured['primary_practice_area']))         return 'identifying_area';
        return 'collecting_minimum_data';
    }
}
