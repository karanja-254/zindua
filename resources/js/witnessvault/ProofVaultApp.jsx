import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { registerVault, unlockVault } from './api';
import EvidenceDashboard from './EvidenceDashboard';

const MAX_CODE_LENGTH = 48;
const REVEAL_MS = 600;

const NUMBER_ROW = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'];
const QWERTY_ROWS = [
    ['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p'],
    ['a', 's', 'd', 'f', 'g', 'h', 'j', 'k', 'l'],
    ['z', 'x', 'c', 'v', 'b', 'n', 'm'],
];

const GAME_MODES = [
    { emoji: '🍉', label: 'Classic Slice', tag: 'Endless combos' },
    { emoji: '🍏', label: 'Zen Garden', tag: '90s pure focus' },
    { emoji: '🍊', label: 'Arcade Rush', tag: 'Beat the clock' },
];

function Key({ children, onPress, wide, accent, active }) {
    return (
        <button
            type="button"
            onClick={onPress}
            className={`select-none rounded-lg px-1 py-2 text-sm font-black shadow-md transition active:scale-95 ${
                wide ? 'col-span-2' : ''
            } ${
                accent
                    ? 'bg-red-600 text-white'
                    : active
                        ? 'bg-yellow-400 text-slate-900'
                        : 'bg-white/25 text-white hover:bg-white/40'
            }`}
        >
            {children}
        </button>
    );
}

export default function ProofVaultApp() {
    const [authUser, setAuthUser] = useState(null);
    const [authMode, setAuthMode] = useState('login');
    const [registerStep, setRegisterStep] = useState('name');
    const [senseiName, setSenseiName] = useState('');
    const [code, setCode] = useState('');
    const [revealLast, setRevealLast] = useState(false);
    const [caps, setCaps] = useState(false);
    const [shake, setShake] = useState(false);
    const [unlocking, setUnlocking] = useState(false);
    const revealTimerRef = useRef(null);

    const maskPasskey = authMode === 'login' || registerStep === 'passkey';

    const displayValue = useMemo(() => {
        if (code === '') {
            return '';
        }
        if (!maskPasskey) {
            return code;
        }
        if (revealLast) {
            return `${'•'.repeat(Math.max(0, code.length - 1))}${code.slice(-1)}`;
        }
        return '•'.repeat(code.length);
    }, [code, revealLast, maskPasskey]);

    const flashReveal = useCallback(() => {
        setRevealLast(true);
        if (revealTimerRef.current) {
            clearTimeout(revealTimerRef.current);
        }
        revealTimerRef.current = setTimeout(() => setRevealLast(false), REVEAL_MS);
    }, []);

    const setClippedCode = useCallback((next) => {
        setCode(next.slice(0, MAX_CODE_LENGTH));
    }, []);

    const appendChar = useCallback((char) => {
        setClippedCode(code + char);
        if (maskPasskey) {
            flashReveal();
        }
    }, [code, flashReveal, maskPasskey, setClippedCode]);

    const failPlay = useCallback(() => {
        setShake(true);
        setTimeout(() => setShake(false), 400);
        setCode('');
        setRevealLast(false);
    }, []);

    const enterVault = useCallback((payload) => {
        sessionStorage.setItem('vault_token', payload.access_token);
        sessionStorage.setItem('pv_token', payload.access_token);
        localStorage.setItem('pv_token', payload.access_token);
        sessionStorage.setItem('vault_user', JSON.stringify(payload.user ?? {}));
        setAuthUser(payload.user ?? {});
        setCode('');
        setSenseiName('');
        setRegisterStep('name');
    }, []);

    const handleLogin = useCallback(async () => {
        const entry = code.trim();
        if (entry === '') {
            failPlay();
            return;
        }
        setUnlocking(true);
        try {
            enterVault(await unlockVault(entry));
        } catch {
            failPlay();
        } finally {
            setUnlocking(false);
        }
    }, [code, enterVault, failPlay]);

    const handleRegister = useCallback(async () => {
        if (registerStep === 'name') {
            const name = code.trim();
            if (name === '') {
                failPlay();
                return;
            }
            setSenseiName(name);
            setCode('');
            setRevealLast(false);
            setRegisterStep('passkey');
            return;
        }

        const entry = code.trim();
        if (entry.length < 4 || senseiName.trim() === '') {
            failPlay();
            return;
        }

        setUnlocking(true);
        try {
            enterVault(await registerVault(senseiName.trim(), entry));
        } catch {
            failPlay();
        } finally {
            setUnlocking(false);
        }
    }, [code, enterVault, failPlay, registerStep, senseiName]);

    const switchMode = useCallback((mode) => {
        setAuthMode(mode);
        setRegisterStep('name');
        setCode('');
        setSenseiName('');
        setRevealLast(false);
        setShake(false);
    }, []);

    useEffect(() => {
        document.title = 'Fruit Ninja Dojo';
    }, []);

    useEffect(() => () => {
        if (revealTimerRef.current) {
            clearTimeout(revealTimerRef.current);
        }
    }, []);

    if (authUser !== null) {
        return <EvidenceDashboard user={authUser} />;
    }

    const letter = (ch) => (caps ? ch.toUpperCase() : ch);
    const isRegister = authMode === 'register';
    const prompt = isRegister
        ? (registerStep === 'name' ? 'ENTER SENSEI NAME' : 'CREATE PASSKEY')
        : 'ENTER KUMITE ID';
    const actionLabel = unlocking
        ? '…'
        : isRegister
            ? (registerStep === 'name' ? 'NEXT' : 'CREATE & ENTER')
            : 'PLAY';

    return (
        <div className="flex min-h-screen flex-col justify-between overflow-hidden bg-gradient-to-b from-green-500 via-emerald-600 to-green-700 p-4 text-white">
            <header className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/20 text-2xl shadow">🥷</span>
                    <div>
                        <p className="text-[10px] font-black uppercase tracking-[0.35em] text-yellow-200">Fruit Ninja</p>
                        <h1 className="text-2xl font-black leading-none tracking-tight">Dojo</h1>
                    </div>
                </div>
                <div className="flex flex-wrap justify-end gap-2">
                    <span className="rounded-full bg-black/30 px-3 py-1 text-xs font-black">🪙 Coins: 12,480</span>
                    <span className="rounded-full bg-black/30 px-3 py-1 text-xs font-black">🔥 Streak: 14</span>
                    <span className="rounded-full bg-black/30 px-3 py-1 text-xs font-black">⭐ Level: 9 Sensei</span>
                </div>
            </header>

            <section className="mt-4 grid grid-cols-3 gap-3">
                {GAME_MODES.map((mode) => (
                    <div
                        key={mode.label}
                        className="flex flex-col items-center gap-1 rounded-2xl bg-black/25 p-4 shadow-lg"
                    >
                        <span className="text-3xl">{mode.emoji}</span>
                        <span className="text-sm font-black">{mode.label}</span>
                        <span className="text-center text-[10px] leading-tight text-white/80">{mode.tag}</span>
                    </div>
                ))}
            </section>

            <section className="mt-4 flex min-h-0 flex-1 flex-col">
                <div className="mb-3 grid grid-cols-2 gap-2">
                    <button
                        type="button"
                        onClick={() => switchMode('login')}
                        className={`rounded-xl py-2 text-xs font-black uppercase tracking-[0.2em] ${
                            authMode === 'login' ? 'bg-yellow-400 text-slate-900' : 'bg-black/30 text-white/80'
                        }`}
                    >
                        ENTER DOJO
                    </button>
                    <button
                        type="button"
                        onClick={() => switchMode('register')}
                        className={`rounded-xl py-2 text-xs font-black uppercase tracking-[0.2em] ${
                            isRegister ? 'bg-yellow-400 text-slate-900' : 'bg-black/30 text-white/80'
                        }`}
                    >
                        JOIN SENSEI
                    </button>
                </div>

                <p className="text-center text-[10px] font-black uppercase tracking-[0.4em] text-yellow-200">
                    {prompt}
                </p>
                {isRegister && registerStep === 'passkey' && senseiName !== '' && (
                    <p className="mt-1 text-center text-xs font-bold text-white/80">Sensei {senseiName}</p>
                )}
                <input
                    type="text"
                    readOnly
                    inputMode="none"
                    value={displayValue}
                    placeholder={maskPasskey ? '• • • •' : 'Your name'}
                    aria-label={prompt}
                    className={`mt-2 w-full rounded-xl bg-black/30 py-3 text-center text-2xl font-black ${
                        maskPasskey ? 'tracking-[0.45em]' : 'tracking-wide'
                    } text-yellow-200 placeholder:text-white/40 focus:outline-none ${
                        shake ? 'animate-shake' : ''
                    }`}
                />

                <div className="mt-3 flex flex-col gap-1.5">
                    <div className="grid grid-cols-10 gap-1">
                        {NUMBER_ROW.map((k) => (
                            <Key key={k} onPress={() => appendChar(k)}>{k}</Key>
                        ))}
                    </div>
                    {QWERTY_ROWS.map((row) => (
                        <div
                            key={row[0]}
                            className={`grid gap-1 ${row.length === 10 ? 'grid-cols-10' : row.length === 9 ? 'grid-cols-9' : 'grid-cols-7'}`}
                        >
                            {row.map((k) => (
                                <Key key={k} onPress={() => appendChar(letter(k))}>
                                    {letter(k)}
                                </Key>
                            ))}
                        </div>
                    ))}
                    <div className="grid grid-cols-5 gap-1">
                        <Key active={caps} onPress={() => setCaps((v) => !v)}>
                            {caps ? 'CAPS' : '⇧ CAPS'}
                        </Key>
                        <Key wide onPress={() => appendChar(' ')}>space</Key>
                        <Key accent onPress={() => { setCode(''); setRevealLast(false); }}>C</Key>
                        <Key accent onPress={() => setClippedCode(code.slice(0, -1))}>⌫</Key>
                    </div>
                </div>

                <button
                    type="button"
                    onClick={isRegister ? handleRegister : handleLogin}
                    disabled={unlocking}
                    className="mt-4 w-full rounded-2xl bg-gradient-to-r from-yellow-300 to-amber-400 py-4 text-lg font-black uppercase tracking-[0.25em] text-slate-900 shadow-lg disabled:opacity-60"
                >
                    {actionLabel}
                </button>
            </section>
        </div>
    );
}
