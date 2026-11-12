// Records each TYPO3 notification with its actions.
export const notificationCalls = [];

const record = (severity) => (title, message, duration, actions = []) => {
    notificationCalls.push({severity, title, message, duration, actions});
};

export default {
    info: record('info'),
    success: record('success'),
    warning: record('warning'),
    error: record('error'),
    notice: record('notice'),
};
