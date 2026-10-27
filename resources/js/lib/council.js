export function isRunning(room) {
    return Boolean(room) && room.status !== 'completed' && room.status !== 'interrupted';
}
