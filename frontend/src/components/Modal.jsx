import { useEffect, useRef } from 'react';

export default function Modal({ open, onClose, title, description, children }) {
  const panelRef = useRef(null);

  useEffect(() => {
    if (!open) return;
    function onKey(e) {
      if (e.key === 'Escape') onClose();
    }
    document.addEventListener('keydown', onKey);
    // Focus the panel for accessibility
    panelRef.current?.focus();
    // Lock body scroll while open
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prev;
    };
  }, [open, onClose]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      {/* Backdrop */}
      <div
        className="absolute inset-0 bg-[var(--color-ink)]/40 backdrop-blur-[2px] animate-[fadeIn_0.15s_ease-out]"
        onClick={onClose}
        aria-hidden="true"
      />
      {/* Panel */}
      <div
        ref={panelRef}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        className="relative w-full max-w-md bg-[var(--color-surface)] rounded-[var(--radius-card)] shadow-[0_20px_60px_-12px_rgba(15,23,41,0.35)] border border-[var(--color-line)] outline-none animate-[popIn_0.18s_cubic-bezier(0.34,1.56,0.64,1)]"
      >
        <div className="p-6">
          {title && (
            <h2 className="text-lg font-semibold tracking-tight text-[var(--color-ink)]">{title}</h2>
          )}
          {description && (
            <p className="mt-1 text-sm text-[var(--color-ink-2)]">{description}</p>
          )}
          <div className={title ? 'mt-5' : ''}>{children}</div>
        </div>
      </div>
    </div>
  );
}
