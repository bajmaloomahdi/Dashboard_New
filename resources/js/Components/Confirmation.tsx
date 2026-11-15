import type { MouseEvent, ReactNode } from 'react';
import { Modal } from 'antd';
import type { ModalFuncProps, PopconfirmProps } from 'antd';
import './confirmation.css';

export function showConfirmation(config: ModalFuncProps) {
    return Modal.confirm({
        okText: 'تأیید',
        cancelText: 'انصراف',
        autoFocusButton: 'cancel',
        ...config,
        centered: true,
        className: ['raga-confirmation', config.className].filter(Boolean).join(' '),
    });
}

export function askConfirmation(config: ModalFuncProps): Promise<boolean> {
    return new Promise((resolve) => {
        showConfirmation({
            ...config,
            onOk: () => resolve(true),
            onCancel: () => resolve(false),
            afterClose: () => resolve(false),
        });
    });
}

type ConfirmationProps = Pick<PopconfirmProps,
    'title' | 'description' | 'onConfirm' | 'onCancel' | 'okText' | 'cancelText' |
    'okButtonProps' | 'cancelButtonProps' | 'disabled' | 'icon'> & { children: ReactNode };

export function Confirmation({
    title, description, onConfirm, onCancel, okText, cancelText,
    okButtonProps, cancelButtonProps, disabled, icon, children,
}: ConfirmationProps) {
    const open = (event: MouseEvent<HTMLSpanElement>) => {
        if (disabled || event.defaultPrevented || !(event.target instanceof Element)) return;
        const button = event.target.closest('button, [role="button"]');
        if (button?.matches(':disabled, [aria-disabled="true"], .ant-btn-loading')) return;
        showConfirmation({
            title: typeof title === 'function' ? title() : title,
            content: typeof description === 'function' ? description() : description,
            onOk: () => onConfirm?.(),
            onCancel: () => onCancel?.(),
            okText: okText ?? 'تأیید',
            cancelText: cancelText ?? 'انصراف',
            okButtonProps: { danger: button?.classList.contains('ant-btn-dangerous'), ...okButtonProps },
            cancelButtonProps,
            ...(icon === undefined ? {} : { icon }),
        });
    };

    return <span className="raga-confirmation-trigger" onClickCapture={open} onClick={event => event.stopPropagation()}>{children}</span>;
}