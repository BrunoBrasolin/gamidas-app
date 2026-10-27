import type { LucideIcon } from 'lucide-react';
import { Eye, Hourglass, Shuffle, Split, Target } from 'lucide-react';
import type { AttentionType } from '@/types';

/**
 * Texts of each challenge of the attention game. They are classroom
 * demonstrations of the concepts, not validated psychological tests.
 */
export const ATTENTION_INFO: Record<
    AttentionType,
    {
        label: string;
        challenge: string;
        icon: LucideIcon;
        /** Tailwind classes for the accent of the challenge. */
        accent: string;
        bar: string;
        instruction: string;
        definition: string;
        explanation: string;
    }
> = {
    selective: {
        label: 'Atenção seletiva',
        challenge: 'Encontre o intruso',
        icon: Target,
        accent: 'from-sky-500 to-cyan-400',
        bar: 'bg-sky-500',
        instruction:
            'Toque em todas as letras Q escondidas no meio dos O. Cuidado: tocar num O conta como erro.',
        definition:
            'Focar em um estímulo importante e ignorar as distrações ao redor.',
        explanation:
            'Para achar o Q você precisou filtrar dezenas de O parecidos e focar só no detalhe que importava — a “perninha” da letra.',
    },
    sustained: {
        label: 'Atenção sustentada',
        challenge: 'Fique de olho!',
        icon: Hourglass,
        accent: 'from-amber-500 to-orange-400',
        bar: 'bg-amber-500',
        instruction:
            'Vários símbolos vão passar, um de cada vez. Conte quantas estrelas cheias ★ aparecem. Não vale a estrela vazia ☆!',
        definition:
            'Manter a concentração em uma mesma atividade durante um período de tempo.',
        explanation:
            'Você teve que manter o foco do começo ao fim da sequência: um segundo de distração e uma estrela passava sem ser contada.',
    },
    divided: {
        label: 'Atenção dividida',
        challenge: 'Duas coisas ao mesmo tempo',
        icon: Split,
        accent: 'from-fuchsia-500 to-pink-400',
        bar: 'bg-fuchsia-500',
        instruction:
            'Cada cartão mostra um símbolo e um número. Conte AO MESMO TEMPO quantos ● círculos aparecem e quantas vezes aparece o número 7.',
        definition:
            'Distribuir a atenção entre duas ou mais demandas que acontecem simultaneamente.',
        explanation:
            'Você acompanhou duas contagens de uma vez. Repare como é mais difícil acertar as duas do que se fosse só uma!',
    },
    alternating: {
        label: 'Atenção alternada',
        challenge: 'Mudou a regra!',
        icon: Shuffle,
        accent: 'from-emerald-500 to-lime-400',
        bar: 'bg-emerald-500',
        instruction:
            'Formas vão aparecer no centro. Toque na tela SÓ quando aparecer um ● círculo. No meio do caminho a regra vai mudar — fique atento!',
        definition:
            'Mudar o foco de uma tarefa para outra conforme a necessidade.',
        explanation:
            'Quando a regra mudou, você precisou largar o alvo antigo (círculo) e passar para o novo (quadrado). Tocar no círculo depois da troca é chamado de perseveração.',
    },
    voluntary: {
        label: 'Atenção voluntária',
        challenge: 'Leia antes de clicar!',
        icon: Eye,
        accent: 'from-violet-500 to-indigo-400',
        bar: 'bg-violet-500',
        instruction:
            'Cada rodada traz uma instrução diferente. Leia com calma e faça exatamente o que ela pede — às vezes a pegadinha está no texto.',
        definition:
            'Direcionar a atenção de forma intencional e consciente para alcançar um objetivo.',
        explanation:
            'Você precisou controlar o impulso de tocar no que chama mais a atenção e seguir de propósito a instrução escrita.',
    },
};

export const ATTENTION_ORDER: AttentionType[] = [
    'selective',
    'sustained',
    'divided',
    'alternating',
    'voluntary',
];

/** "12,3 s" */
export function formatSeconds(ms: number | null): string {
    if (ms === null) {
        return '—';
    }

    return `${(ms / 1000).toLocaleString('pt-BR', {
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
    })} s`;
}

/** "2 min 05 s" */
export function formatElapsed(seconds: number | null): string {
    if (seconds === null) {
        return '—';
    }

    const minutes = Math.floor(seconds / 60);
    const rest = String(seconds % 60).padStart(2, '0');

    return minutes > 0 ? `${minutes} min ${rest} s` : `${seconds} s`;
}

export function formatRate(percent: number | null): string {
    return percent === null ? '—' : `${percent}%`;
}

export function formatPoints(points: number | null): string {
    return points === null ? '—' : points.toLocaleString('pt-BR');
}

export function shuffle<T>(items: T[]): T[] {
    const copy = [...items];

    for (let i = copy.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [copy[i], copy[j]] = [copy[j], copy[i]];
    }

    return copy;
}

export function randomInt(min: number, max: number): number {
    return min + Math.floor(Math.random() * (max - min + 1));
}

/** Four answer options around the right count, never negative, shuffled. */
export function countOptions(answer: number): number[] {
    const options = new Set([answer]);

    for (const delta of shuffle([-2, -1, 1, 2, 3])) {
        if (options.size === 4) {
            break;
        }

        if (answer + delta >= 0) {
            options.add(answer + delta);
        }
    }

    return [...options].sort((a, b) => a - b);
}
