export default function ModelSelect({ models, value, onChange, ...props }) {
    return (
        <select value={models.includes(value) ? value : models[0] ?? ''} onChange={(event) => onChange(event.target.value)} {...props}>
            {models.map((model) => (
                <option key={model} value={model}>
                    {model}
                </option>
            ))}
        </select>
    );
}
