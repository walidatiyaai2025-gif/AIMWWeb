namespace AIWordPressManager.Application.Common.Results;

public sealed class Result<TValue> : Result
{
    private readonly TValue? _value;

    internal Result(TValue value)
        : base(true, Error.None)
    {
        _value = value;
    }

    internal Result(Error error)
        : base(false, error)
    {
    }

    public TValue Value => IsSuccess
        ? _value!
        : throw new InvalidOperationException("The value of a failed result cannot be accessed.");
}
