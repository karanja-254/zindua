import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import ProofVaultApp from './ProofVaultApp';
import '../../css/app.css';

const container = document.getElementById('vault-root');

if (container) {
    createRoot(container).render(
        <StrictMode>
            <ProofVaultApp />
        </StrictMode>,
    );
}
