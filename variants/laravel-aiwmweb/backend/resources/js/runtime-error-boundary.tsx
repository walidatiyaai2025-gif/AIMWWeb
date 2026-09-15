import React from 'react';
import { RuntimeErrorOpenLogsControl } from './runtime-error-open-logs-control';

export const RUNTIME_ERROR_RECOVER_OPERATION_ID = 'AIMW-PLAT-C6260410D1';
export const RUNTIME_ERROR_HARD_RELOAD_OPERATION_ID = 'AIMW-PLAT-4BAE8344AF';

type RuntimeErrorBoundaryState = { error: Error | null };

export function RuntimeErrorHardReloadControl() {
    return (
        <a
            className="btn"
            href={window.location.href}
            data-canonical-operation={RUNTIME_ERROR_HARD_RELOAD_OPERATION_ID}
        >Hard reload</a>
    );
}

export class RuntimeErrorBoundary extends React.Component<{ children: React.ReactNode }, RuntimeErrorBoundaryState> {
    state: RuntimeErrorBoundaryState = { error: null };

    static getDerivedStateFromError(error: Error): RuntimeErrorBoundaryState {
        return { error };
    }

    componentDidCatch(error: Error, info: React.ErrorInfo) {
        console.error('Laravel AIWMWeb frontend error', error, info.componentStack);
    }

    private recover = () => {
        this.setState({ error: null });
    };

    render() {
        if (this.state.error) {
            return (
                <div className="fatal-error" role="alert">
                    <section className="panel">
                        <span className="workspace-kicker">RUNTIME ERROR</span>
                        <h1>A runtime error interrupted this screen</h1>
                        <p>{this.state.error.message}</p>
                        <div className="d-flex gap-2 flex-wrap">
                            <button
                                type="button"
                                className="btn primary"
                                data-canonical-operation={RUNTIME_ERROR_RECOVER_OPERATION_ID}
                                onClick={this.recover}
                            >Try to recover</button>
                            <RuntimeErrorHardReloadControl />
                            <RuntimeErrorOpenLogsControl />
                        </div>
                    </section>
                </div>
            );
        }

        return this.props.children;
    }
}
