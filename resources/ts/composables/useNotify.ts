import { toast } from 'vue-sonner'

/**
 * Action feedback for the NOC.
 *
 * Rule: toasts confirm what the OPERATOR just did — acknowledged, cleared,
 * dispatched, saved, imported. They are dismissible and time-limited, so they must
 * never be the only place a NETWORK event appears. Alarms belong in the alarm list
 * and the persistent banner; a critical alarm that auto-hides after 5s is a missed
 * outage.
 *
 * Use `notify.error` for a failed action, not for a device being down.
 */
export function useNotify() {
  return {
    /** An operator action completed. */
    success: (message: string, description?: string) =>
      toast.success(message, { description }),

    /** An operator action failed — always say what to do next. */
    error: (message: string, description?: string) =>
      toast.error(message, { description, duration: 8000 }),

    /** Completed, but with a caveat the operator should read. */
    warning: (message: string, description?: string) =>
      toast.warning(message, { description }),

    /** Neutral confirmation. */
    info: (message: string, description?: string) =>
      toast(message, { description }),

    /**
     * Wrap an in-flight request so the operator sees pending → result rather than a
     * frozen button. Returns the original promise so callers can still await it.
     */
    promise: <T>(p: Promise<T>, msg: { loading: string, success: string, error: string }) => {
      toast.promise(p, msg)

      return p
    },

    /** Undo affordance for a bulk or destructive action. */
    undo: (message: string, onUndo: () => void) =>
      toast.success(message, { action: { label: 'Undo', onClick: onUndo } }),

    dismiss: (id?: number | string) => toast.dismiss(id),
  }
}
