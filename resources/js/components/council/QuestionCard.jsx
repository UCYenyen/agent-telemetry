export default function QuestionCard({ message }) {
    return (
        <div className="c-msg-question">
            <div className="c-msg-question-head">OPERATOR PROPOSITION // COUNCIL CONVENED</div>
            <div className="c-msg-question-body">{message.content}</div>
        </div>
    );
}
