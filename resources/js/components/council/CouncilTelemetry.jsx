import StagePill from './StagePill';
import RadarCard from './RadarCard';

function stageClass(stage, current) {
    if (current === stage) {
        return 'active';
    }

    return current > stage ? 'completed' : 'waiting';
}

function advisorStatus(advisor, stage) {
    if (stage === 1 && advisor.status === 'thinking') {
        return { badge: 'pulse', text: 'THINKING [RUN]', action: advisor.action, thinking: true };
    }

    if (stage === 1) {
        return { badge: 'done', text: advisor.stage1_elapsed ? `DONE (${advisor.stage1_elapsed}s) OK` : 'DONE OK', action: advisor.action };
    }

    if (stage === 2 && advisor.peer_status === 'thinking') {
        return { badge: 'pulse', text: 'CRITIQUING [REVIEW]', action: advisor.peer_action || 'Cross-examining responses A–E…', thinking: true };
    }

    if (stage === 2 && advisor.peer_status === 'done') {
        return { badge: 'done', text: advisor.peer_elapsed ? `REVIEWED (${advisor.peer_elapsed}s) OK` : 'REVIEWED OK', action: advisor.peer_action || 'Critique delivered' };
    }

    return { badge: 'done', text: stage === 2 ? 'TAKE READY OK' : 'COMPLETE OK', action: advisor.action };
}

function chairmanStatus(chairman, stage) {
    if (stage === 3) {
        return { badge: 'pulse', text: 'SYNTHESIZING [COUNCIL]', thinking: true };
    }

    return stage >= 4 ? { badge: 'done', text: `VERDICT (${chairman.elapsed ?? ''}s) OK`, done: true } : { badge: 'waiting', text: 'WAITING', done: false };
}

export default function CouncilTelemetry({ progress, running }) {
    const stage = progress.stage ?? 1;
    const advisors = Object.values(progress.advisors ?? {});
    const totalAdvisors = progress.total_advisors || advisors.length;
    const doneAdvisors = progress.completed_advisors ?? 0;
    const totalReviewers = progress.total_reviewers ?? 0;
    const doneReviewers = progress.completed_reviewers ?? 0;
    const chairman = progress.chairman ?? {};

    const stage1Text = stage === 1 ? `${doneAdvisors}/${totalAdvisors} COMPLETED` : stage > 1 ? 'COMPLETED OK' : 'WAITING';
    const stage2Text = totalReviewers === 0 ? 'SKIPPED' : stage === 2 ? `${doneReviewers}/${totalReviewers} REVIEWED` : stage > 2 ? 'COMPLETED OK' : 'WAITING';
    const stage3Text = stage === 3 ? 'SYNTHESIZING...' : stage >= 4 ? 'VERDICT DELIVERED OK' : 'WAITING';

    return (
        <div className="c-progress-hub">
            <div className="c-progress-head">
                <span className="c-progress-title">COUNCIL TELEMETRY RADAR // {progress.stage_name || 'DELIBERATION PIPELINE'}</span>
                {running ? <span className="badge fallback">LIVE DELIBERATING</span> : <span className="badge antigravity">DELIBERATION CONCLUDED</span>}
            </div>

            <div className="c-stepper">
                <StagePill
                    state={stageClass(1, stage)}
                    title="01 // INDEPENDENT TAKES"
                    status={stage1Text}
                    percent={Math.round((doneAdvisors / Math.max(1, totalAdvisors)) * 100)}
                    caption={`${doneAdvisors} of ${totalAdvisors} independent advisors`}
                />
                <StagePill
                    state={stageClass(2, stage)}
                    title="02 // PEER REVIEW (A–E)"
                    status={stage2Text}
                    percent={totalReviewers > 0 ? Math.round((doneReviewers / totalReviewers) * 100) : 0}
                    caption={`${doneReviewers} of ${totalReviewers} anonymous reviews`}
                />
                <StagePill
                    state={stageClass(3, stage)}
                    title="03 // CHAIRMAN VERDICT"
                    status={stage3Text}
                    percent={stage >= 4 ? 100 : stage === 3 ? 60 : 0}
                    caption="Synthesis & Actionable Recommendation"
                />
            </div>

            <div className="c-radar-grid">
                {advisors.map((advisor) => (
                    <RadarCard key={advisor.id} icon={advisor.icon || '[MEM]'} name={advisor.name} model={advisor.model} color={advisor.color} {...advisorStatus(advisor, stage)} />
                ))}
                <RadarCard
                    chairman
                    icon="[COUNCIL]"
                    name="THE CHAIRMAN (SYNTHESIS & FINAL VERDICT)"
                    model={`CHAIRMAN MODEL: ${chairman.model ?? ''}`}
                    action={chairman.action || 'Waiting for deliberations…'}
                    {...chairmanStatus(chairman, stage)}
                />
            </div>
        </div>
    );
}
