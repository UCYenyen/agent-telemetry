import WorkingIndicator from '../common/WorkingIndicator';
import CouncilTelemetry from './CouncilTelemetry';
import InterruptedBanner from './InterruptedBanner';
import QuestionCard from './QuestionCard';
import StageDivider from './StageDivider';
import TakeCard from './TakeCard';
import VerdictCard from './VerdictCard';

const DIVIDERS = {
    advisor_take: 'STAGE 01 // INDEPENDENT ADVISOR DELIBERATIONS (PARALLEL)',
    peer_review: 'STAGE 02 // ANONYMOUS PEER REVIEW & CROSS-EXAMINATION (RESPONSES A–E)',
    chairman_verdict: 'STAGE 03 // CHAIRMAN FINAL SYNTHESIS & VERDICT',
};

function streamItems(messages) {
    const items = [];
    let shown = new Set();

    for (const message of messages) {
        if (message.type === 'user_question') {
            shown = new Set();
            items.push(<QuestionCard key={message.id} message={message} />);
            continue;
        }

        if (DIVIDERS[message.type] && (message.type === 'chairman_verdict' || !shown.has(message.type))) {
            shown.add(message.type);
            items.push(<StageDivider key={`divider-${message.id}`} label={DIVIDERS[message.type]} />);
        }

        items.push(message.type === 'chairman_verdict' ? <VerdictCard key={message.id} message={message} /> : <TakeCard key={message.id} message={message} />);
    }

    return items;
}

export default function CouncilStream({ room, running, onAsk }) {
    const lastQuestion = room.messages.findLast((message) => message.type === 'user_question')?.content;

    return (
        <>
            {room.status === 'interrupted' ? <InterruptedBanner question={lastQuestion} onAsk={onAsk} /> : room.progress && <CouncilTelemetry progress={room.progress} running={running} />}
            {streamItems(room.messages)}
            {running && <WorkingIndicator label={room.status_detail || 'Council advisors deliberating in parallel…'} />}
        </>
    );
}
